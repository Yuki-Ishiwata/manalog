(() => {
  const panel = document.getElementById('install-panel');
  if (!panel) return;
  const status = document.getElementById('install-status');
  const button = document.getElementById('install-button');
  const isIos = /iPhone|iPad|iPod/.test(navigator.userAgent);
  const isInstalled = navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  let installPrompt = null;

  const showInstalled = () => {
    status.textContent = 'ホーム画面から起動しています。このまま通知設定へ進めます。';
    button.hidden = true;
  };
  if (isInstalled) showInstalled();
  else if (isIos) {
    status.textContent = 'Safariの共有ボタンから「ホーム画面に追加」を選び、追加したアイコンからまなログを開いてください。Chromeの場合も共有メニューから追加できます。';
    button.hidden = true;
  } else {
    status.textContent = 'この画面をホーム画面に追加できます。追加しない場合も、設定を完了して利用できます。';
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    button.hidden = false;
  });
  window.addEventListener('appinstalled', showInstalled);
  button.addEventListener('click', async () => {
    if (!installPrompt) {
      status.textContent = 'ブラウザのメニューから「ホーム画面に追加」または「アプリをインストール」を選んでください。';
      return;
    }
    button.disabled = true;
    await installPrompt.prompt();
    const result = await installPrompt.userChoice;
    installPrompt = null;
    if (result?.outcome === 'accepted') status.textContent = '追加処理を行いました。ホーム画面のアイコンから起動すると、通知を設定できます。';
    else status.textContent = '追加は保留されました。あとからブラウザのメニューから追加できます。';
    button.hidden = true;
  });
})();
