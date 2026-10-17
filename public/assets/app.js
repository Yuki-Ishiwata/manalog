(() => {
  const form = document.getElementById('chat-form');
  const button = document.getElementById('voice-button');
  const textarea = document.getElementById('chat-message');
  const sendButton = document.getElementById('send-button');
  const status = document.getElementById('voice-status');
  const messages = document.getElementById('chat-messages');
  const title = document.getElementById('chat-title');
  const errorBox = document.getElementById('chat-error');
  if (!form || !button || !textarea || !sendButton || !status || !messages || !title || !errorBox) return;

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  let voiceMode = false;
  let recognition = null;
  let listening = false;
  let waiting = false;
  let restartTimer = null;

  const showError = (message) => {
    errorBox.textContent = message;
    errorBox.hidden = false;
  };
  const clearError = () => {
    errorBox.textContent = '';
    errorBox.hidden = true;
  };
  const addBubble = (role, content) => {
    const bubble = document.createElement('div');
    bubble.className = 'bubble ' + role;
    bubble.textContent = content;
    messages.appendChild(bubble);
    bubble.scrollIntoView({ behavior: 'smooth', block: 'end' });
    return bubble;
  };
  const updateVoiceButton = () => {
    button.textContent = voiceMode ? '■ 会話モードを終了' : '🎙 会話モードを開始';
  };
  const stopVoice = () => {
    voiceMode = false;
    clearTimeout(restartTimer);
    if (recognition) recognition.stop();
    updateVoiceButton();
  };

  async function sendMessage() {
    if (waiting || !textarea.value.trim()) return;
    waiting = true;
    clearError();
    const message = textarea.value.trim();
    const payload = new FormData(form);
    payload.set('message', message);
    const bubble = addBubble('mine', message);
    textarea.value = '';
    textarea.disabled = true;
    sendButton.disabled = true;
    status.textContent = '返事を待っています…';
    try {
      const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || '送信できませんでした。');
      addBubble('theirs', result.reply);
      title.textContent = (result.title || '今日の振り返り') + (result.progress ? ' · ' + result.progress : '');
      if (result.ended) {
        stopVoice();
        form.hidden = true;
      } else {
        status.textContent = voiceMode ? '聞き取り中…話してください。' : '返事が届きました。';
      }
    } catch (error) {
      bubble.remove();
      textarea.value = message;
      stopVoice();
      showError(error.message || '通信できませんでした。もう一度お試しください。');
      status.textContent = '送信できませんでした。';
    } finally {
      waiting = false;
      textarea.disabled = false;
      sendButton.disabled = false;
      if (voiceMode && !listening) scheduleRestart();
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    sendMessage();
  });

  if (!SpeechRecognition) {
    status.textContent = 'このブラウザでは音声入力を利用できません。文字で入力してください。';
    return;
  }

  function scheduleRestart() {
    if (!voiceMode || waiting || listening || restartTimer) return;
    restartTimer = setTimeout(() => {
      restartTimer = null;
      startListening();
    }, 300);
  }

  function startListening() {
    if (!voiceMode || waiting || listening) return;
    const current = new SpeechRecognition();
    recognition = current;
    current.lang = 'ja-JP';
    current.continuous = true;
    current.interimResults = false;
    current.maxAlternatives = 1;
    current.onstart = () => {
      listening = true;
      if (!waiting) status.textContent = '聞き取り中…話してください。';
    };
    current.onresult = (event) => {
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const result = event.results[i];
        if (!result.isFinal) continue;
        const transcript = result[0]?.transcript?.trim();
        if (!transcript || waiting) continue;
        textarea.value = [textarea.value.trim(), transcript].filter(Boolean).join(' ');
        sendMessage();
      }
    };
    current.onerror = (event) => {
      if (!voiceMode) return;
      if (event.error === 'no-speech') {
        status.textContent = '声を待っています…';
        return;
      }
      const errors = {
        'audio-capture': 'マイクを利用できません。端末のマイク設定を確認してください。',
        'not-allowed': 'マイクまたは音声認識が許可されていません。ブラウザのサイト設定を確認してください。',
        'service-not-allowed': 'ブラウザの音声認識サービスを利用できません。',
        network: '音声認識サービスに接続できません。通信状態を確認してください。',
        'language-not-supported': 'このブラウザでは日本語の音声認識を利用できません。',
      };
      stopVoice();
      status.textContent = errors[event.error] || '音声入力が止まりました（' + (event.error || '不明') + '）。もう一度お試しください。';
    };
    current.onend = () => {
      listening = false;
      if (recognition === current) recognition = null;
      scheduleRestart();
    };
    try {
      current.start();
    } catch (error) {
      recognition = null;
      listening = false;
      stopVoice();
      status.textContent = 'ブラウザが自動再開を許可しませんでした。もう一度マイクを押してください。';
    }
  }

  button.hidden = false;
  button.addEventListener('click', () => {
    if (voiceMode) {
      stopVoice();
      status.textContent = '音声入力を終了しました。';
    } else {
      voiceMode = true;
      updateVoiceButton();
      clearError();
      startListening();
    }
  });
  window.addEventListener('pagehide', stopVoice);
})();
