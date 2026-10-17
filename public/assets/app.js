(() => {
  const button = document.getElementById('voice-button');
  const textarea = document.getElementById('chat-message');
  const status = document.getElementById('voice-status');
  if (!button || !textarea || !status) return;

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) {
    status.textContent = 'このブラウザでは音声入力を利用できません。文字で入力してください。';
    return;
  }

  button.hidden = false;
  button.addEventListener('click', () => {
    const recognition = new SpeechRecognition();
    recognition.lang = 'ja-JP';
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;
    button.disabled = true;
    status.textContent = '聞き取り中…';
    recognition.onresult = (event) => {
      const transcript = event.results?.[0]?.[0]?.transcript || '';
      if (transcript) {
        textarea.value = [textarea.value.trim(), transcript].filter(Boolean).join(' ');
        status.textContent = '文字起こしを確認してから送信してください。';
        textarea.focus();
      }
    };
    recognition.onerror = () => {
      status.textContent = '音声を認識できませんでした。文字で入力してください。';
    };
    recognition.onend = () => {
      button.disabled = false;
      if (status.textContent === '聞き取り中…') status.textContent = '音声を認識できませんでした。文字で入力してください。';
    };
    recognition.start();
  });
})();
