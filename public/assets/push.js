(() => {
  const registrationPromise = 'serviceWorker' in navigator ? navigator.serviceWorker.register('sw.js').catch(() => null) : null;
  const panel = document.getElementById('push-panel');
  if (!panel) return;
  const button = document.getElementById('push-subscribe');
  const status = document.getElementById('push-status');
  const key = panel.dataset.vapidKey;
  const csrf = panel.dataset.csrf;
  const onIphone = /iPhone|iPad|iPod/.test(navigator.userAgent);
  const installed = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  const supported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && !!key;
  let registration;
  let activeSubscription = null;

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
  };
  const refresh = async () => {
    if (!supported) {
      button.disabled = true;
      status.textContent = key ? 'この端末またはブラウザではWeb Pushを利用できません。' : '通知のサーバー設定がまだ完了していません。';
      return;
    }
    if (onIphone && !installed) {
      button.disabled = true;
      status.textContent = 'iPhoneでは共有メニューからホーム画面に追加し、そのアイコンから開いてください。';
      return;
    }
    registration = await navigator.serviceWorker.ready;
    activeSubscription = await registration.pushManager.getSubscription();
    button.textContent = activeSubscription ? 'この端末の通知を解除' : 'この端末で通知を受け取る';
    status.textContent = activeSubscription ? 'この端末で通知を受け取る設定です。' : '通知を受け取るには、この端末で許可してください。';
    button.disabled = false;
  };

  if (registrationPromise) registrationPromise.then((registered) => { if (registered) refresh(); else status.textContent = 'アプリの登録に失敗しました。ページを更新してください。'; });
  else refresh();

  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      // The permission prompt must follow this student-initiated tap.
      let subscription = activeSubscription;
      if (subscription) {
        await post('unsubscribe', subscription.toJSON());
        await subscription.unsubscribe();
      } else {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') throw new Error('通知が許可されていません。端末の通知設定を確認してください。');
        subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toBytes(key) });
        try { await post('subscribe', subscription.toJSON()); }
        catch (error) { await subscription.unsubscribe(); throw error; }
      }
      await refresh();
    } catch (error) {
      status.textContent = error.message || '通知設定を変更できませんでした。';
      button.disabled = false;
    }
  });
})();
