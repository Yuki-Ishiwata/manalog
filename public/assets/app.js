(() => {
  const button = document.getElementById('voice-button');
  const textarea = document.getElementById('chat-message');
  const status = document.getElementById('voice-status');
  if (!button || !textarea || !status) return;
  const form = textarea.form;
  const sendButton = form.querySelector('button[type="submit"]');
  form.addEventListener('submit', () => {
    button.disabled = true;
    sendButton.disabled = true;
    status.textContent = '送信中…返事を待っています。';
  });

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) {
    status.textContent = 'このブラウザでは音声入力を利用できません。文字で入力してください。';
    return;
  }

  button.hidden = false;
  button.addEventListener('click', () => {
    const recognition = new SpeechRecognition();
    let recognized = false;
    let hadError = false;
    let submitted = false;
    recognition.lang = 'ja-JP';
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;
    button.disabled = true;
    status.textContent = 'マイクを起動しています…';
    recognition.onstart = () => {
      status.textContent = '聞き取り中…話してください。';
    };
    recognition.onresult = (event) => {
      const transcript = event.results?.[0]?.[0]?.transcript || '';
      if (transcript) {
        textarea.value = [textarea.value.trim(), transcript].filter(Boolean).join(' ');
        recognized = true;
        status.textContent = '聞き取りました。自動で送信します…';
      }
    };
    recognition.onerror = (event) => {
      hadError = true;
      const messages = {
        'no-speech': '声を検出できませんでした。マイクに向かって話し、もう一度お試しください。',
        'audio-capture': 'マイクを利用できません。端末のマイク設定を確認してください。',
        'not-allowed': 'マイクまたは音声認識が許可されていません。ブラウザのサイト設定を確認してください。',
        'service-not-allowed': 'ブラウザの音声認識サービスを利用できません。ブラウザの設定を確認してください。',
        network: '音声認識サービスに接続できません。通信状態を確認してもう一度お試しください。',
        'language-not-supported': 'このブラウザでは日本語の音声認識を利用できません。',
        aborted: '音声入力を中断しました。',
      };
      status.textContent = messages[event.error] || `音声認識でエラーが発生しました（${event.error || '不明'}）。文字で入力してください。`;
      button.disabled = false;
    };
    recognition.onend = () => {
      button.disabled = false;
      if (recognized && !hadError && !submitted) {
        submitted = true;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else sendButton.click();
      } else if (status.textContent === '聞き取り中…話してください。') {
        status.textContent = '声を検出できませんでした。もう一度お試しください。';
      }
    };
    try {
      recognition.start();
    } catch (error) {
      hadError = true;
      button.disabled = false;
      status.textContent = '音声入力を開始できませんでした。ブラウザを更新してもう一度お試しください。';
    }
  });
})();
