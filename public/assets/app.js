(() => {
  if ('clearAppBadge' in navigator) navigator.clearAppBadge().catch(() => {});

  // Native POST forms navigate only after the server finishes. Lock the form
  // immediately and show what is being processed so repeated taps cannot queue.
  const processingMessages = {
    parse_timetable: '時間割の写真を解析しています。少し時間がかかります。',
    start_weekly_review: '今週の記録を確認して、復習を準備しています。',
    refresh_instructor_summary: '最新の学習記録からサマリーを作成しています。',
    send_message: 'AIが返事を作成しています。',
    login: 'ログインしています…',
    setup: 'アカウントを作成しています…',
  };
  document.querySelectorAll('form[method="post" i]').forEach((postForm) => {
    if (postForm.id === 'chat-form') return;
    postForm.addEventListener('submit', (event) => {
      if (event.defaultPrevented || postForm.dataset.submitting === 'true') {
        event.preventDefault();
        return;
      }
      postForm.dataset.submitting = 'true';
      const action = postForm.querySelector('input[name="action"]')?.value || '';
      const submitters = Array.from(postForm.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]'));
      submitters.forEach((submitter) => {
        submitter.disabled = true;
        if (submitter instanceof HTMLButtonElement) {
          submitter.dataset.originalLabel = submitter.textContent;
          submitter.textContent = '処理中…';
          submitter.classList.add('is-processing');
        } else {
          submitter.dataset.originalLabel = submitter.value;
          submitter.value = '処理中…';
          submitter.classList.add('is-processing');
        }
      });
      const statusLine = document.createElement('p');
      statusLine.className = 'form-processing-status';
      statusLine.setAttribute('role', 'status');
      statusLine.setAttribute('aria-live', 'polite');
      statusLine.textContent = processingMessages[action] || '保存しています…';
      postForm.appendChild(statusLine);
      postForm.setAttribute('aria-busy', 'true');
    });
  });
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll('form[data-submitting="true"]').forEach((postForm) => {
      postForm.dataset.submitting = 'false';
      postForm.removeAttribute('aria-busy');
      postForm.querySelector('.form-processing-status')?.remove();
      postForm.querySelectorAll('.is-processing').forEach((submitter) => {
        submitter.disabled = false;
        submitter.classList.remove('is-processing');
        const original = submitter.dataset.originalLabel;
        if (submitter instanceof HTMLButtonElement && original !== undefined) submitter.textContent = original;
        else if (submitter instanceof HTMLInputElement && original !== undefined) submitter.value = original;
      });
    });
  });

  const form = document.getElementById('chat-form');
  const button = document.getElementById('voice-button');
  const textarea = document.getElementById('chat-message');
  const sendButton = document.getElementById('send-button');
  const status = document.getElementById('voice-status');
  const messages = document.getElementById('chat-messages');
  const title = document.getElementById('chat-title');
  const errorBox = document.getElementById('chat-error');
  const photoInput = document.getElementById('chat-photos');
  const photoStatus = document.getElementById('photo-status');
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

  if (photoInput && photoStatus) photoInput.addEventListener('change', () => {
    const files = Array.from(photoInput.files || []);
    if (files.length > 4) {
      photoInput.value = '';
      photoStatus.textContent = '写真は一度に4枚までです。';
      return;
    }
    photoStatus.textContent = files.length ? files.length + '枚を選択中（1枚8MBまで）' : '';
  });

  async function sendMessage() {
    const selectedPhotos = Array.from(photoInput?.files || []);
    if (waiting || (!textarea.value.trim() && selectedPhotos.length === 0)) return;
    waiting = true;
    clearError();
    const message = textarea.value.trim();
    const payload = new FormData(form);
    payload.set('message', message);
    const bubble = addBubble('mine', message || '📷 写真' + selectedPhotos.length + '枚を送信');
    textarea.value = '';
    textarea.disabled = true;
    sendButton.disabled = true;
    if (button) button.disabled = true;
    if (photoInput) photoInput.disabled = true;
    form.setAttribute('aria-busy', 'true');
    status.textContent = selectedPhotos.length
      ? '写真を解析して、返事を作っています。少し時間がかかります…'
      : '返事を待っています…';
    const progressTimer = setTimeout(() => {
      if (waiting) status.textContent = selectedPhotos.length
        ? '写真を解析しています…数十秒かかることがあります。'
        : 'AIが返事を作成しています…';
    }, 5000);
    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || '送信できませんでした。');
      bubble.textContent = result.user_message || message;
      (result.photo_ids || []).forEach((photoId) => {
        const link = document.createElement('a');
        link.className = 'chat-photo-link';
        link.href = '?photo=' + encodeURIComponent(photoId);
        link.target = '_blank';
        link.rel = 'noopener';
        const image = document.createElement('img');
        image.className = 'chat-photo';
        image.src = link.href;
        image.alt = '送信した学習写真';
        link.appendChild(image);
        bubble.appendChild(link);
      });
      if (photoInput) photoInput.value = '';
      if (photoStatus) photoStatus.textContent = '';
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
      clearTimeout(progressTimer);
      waiting = false;
      textarea.disabled = false;
      sendButton.disabled = false;
      button.disabled = false;
      if (photoInput) photoInput.disabled = false;
      form.removeAttribute('aria-busy');
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
