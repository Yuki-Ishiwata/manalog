(() => {
  const registrationPromise = 'serviceWorker' in navigator ? navigator.serviceWorker.register('sw.js').catch(() => null) : null;
  const panel = document.getElementById('push-panel');
  if (!panel) return;
  const button = document.getElementById('push-subscribe');
  const topButton = document.getElementById('push-enable-top');
  const banner = document.getElementById('push-enable-banner');
  const bannerNote = document.getElementById('push-enable-note');
  const status = document.getElementById('push-status');
  const key = panel.dataset.vapidKey;
  const csrf = panel.dataset.csrf;
  const onboarding = panel.dataset.onboarding === '1';
  const onIphone = /iPhone|iPad|iPod/.test(navigator.userAgent);
  const installed = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  const supported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && !!key;
  let registration;
  let activeSubscription = null;
  const reportPushStatus = (enabled, userChanged = false) => window.dispatchEvent(new CustomEvent('manalog:push-status', { detail: { enabled, userChanged } }));
  const setBannerNote = (message) => { if (bannerNote) bannerNote.textContent = message; };

  const toBytes = (base64url) => {
    const padded = (base64url + '='.repeat((4 - base64url.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
  };
  const post = async (action, subscription) => {
    const response = await fetch('push.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ action, subscription }),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || '設定を変更できませんでした。');
    return result;
  };
  const refresh = async () => {
    if (!supported) {
      button.disabled = true;
      if (topButton) topButton.disabled = true;
      if (banner) banner.hidden = false;
      status.textContent = key ? 'この端末またはブラウザではWeb Pushを利用できません。' : '通知のサーバー設定がまだ完了していません。';
      setBannerNote(status.textContent);
      reportPushStatus(false);
      return;
    }
    if (onIphone && !installed) {
      button.disabled = true;
      if (topButton) topButton.disabled = true;
      if (banner) banner.hidden = false;
      status.textContent = 'iPhoneでは共有メニューからホーム画面に追加し、そのアイコンから開いてください。';
      setBannerNote(status.textContent);
      reportPushStatus(false);
      return;
    }
    registration = await navigator.serviceWorker.ready;
    activeSubscription = await registration.pushManager.getSubscription();
    if (Notification.permission === 'denied') {
      button.textContent = '端末の設定を確認してください';
      status.textContent = '通知がブロックされています。ここから許可を再要求しません。端末またはブラウザの設定でまなログの通知を許可してから、ページを開き直してください。';
      button.disabled = true;
      if (topButton) topButton.disabled = true;
      if (banner) banner.hidden = false;
      reportPushStatus(false);
      setBannerNote(status.textContent);
      return;
    }
    button.textContent = activeSubscription
      ? (onboarding ? '通知はオンです' : 'この端末の通知を解除')
      : (onboarding ? '通知をオンにする' : 'この端末で通知を受け取る');
    status.textContent = activeSubscription
      ? 'この端末で通知を受け取る設定です。'
      : (onboarding && Notification.permission === 'default' ? '端末通知はまだ許可されていません。オンにしますか？下のボタンを押すと確認画面が開きます。' : '通知を受け取るには、この端末で許可してください。');
    button.disabled = onboarding && !!activeSubscription;
    if (topButton) topButton.disabled = button.disabled;
    if (banner) banner.hidden = !!activeSubscription;
    setBannerNote(activeSubscription ? '' : (Notification.permission === 'denied' ? '端末またはブラウザの設定から通知を許可してください。' : 'ボタンを押すと、この端末の通知許可を確認します。'));
    reportPushStatus(!!activeSubscription);
  };

  if (registrationPromise) registrationPromise.then((registered) => {
    if (registered) refresh();
    else {
      status.textContent = 'アプリの登録に失敗しました。ページを更新してください。';
      if (banner) banner.hidden = false;
      if (topButton) topButton.disabled = true;
      setBannerNote(status.textContent);
    }
  });
  else refresh();

  const toggleSubscription = async () => {
    button.disabled = true;
    if (topButton) topButton.disabled = true;
    status.textContent = activeSubscription ? 'この端末の通知設定を解除しています…' : '通知の許可を確認しています…';
    try {
      // The permission prompt must follow this student-initiated tap.
      let subscription = activeSubscription;
      let serverPushReady = null;
      if (subscription) {
        if (onboarding) return;
        const result = await post('unsubscribe', subscription.toJSON());
        serverPushReady = !!result.push_ready;
        await subscription.unsubscribe();
      } else {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') throw new Error('通知が許可されていません。端末の通知設定を確認してください。');
        subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toBytes(key) });
        try {
          const result = await post('subscribe', subscription.toJSON());
          serverPushReady = !!result.push_ready;
        }
        catch (error) { await subscription.unsubscribe(); throw error; }
      }
      await refresh();
      if (serverPushReady !== null) reportPushStatus(serverPushReady, true);
    } catch (error) {
      status.textContent = error.message || '通知設定を変更できませんでした。';
      button.disabled = false;
      if (topButton) topButton.disabled = false;
      setBannerNote(status.textContent);
    }
  };
  button.addEventListener('click', toggleSubscription);
  if (topButton) topButton.addEventListener('click', toggleSubscription);
})();
