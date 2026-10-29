(() => {
  document.querySelectorAll('.notification-copy-tools').forEach((tools) => {
    const form = tools.closest('form');
    const status = tools.querySelector('[role="status"]');
    if (!form || !status) return;
    const lastSource = new Map();
    const times = Array.from(form.querySelectorAll('input')).filter((input) => input.name.startsWith('times['));
    times.forEach((input) => {
      const type = input.name.endsWith('[night]') ? 'night' : 'after_school';
      const remember = () => lastSource.set(type, input.name.match(/^times\[(\d+)\]/)?.[1] || '1');
      input.addEventListener('focus', remember);
      input.addEventListener('input', remember);
      input.addEventListener('change', remember);
    });

    tools.querySelectorAll('[data-copy-notification-time]').forEach((button) => {
      button.addEventListener('click', () => {
        const type = button.dataset.copyNotificationTime;
        let sourceDay = lastSource.get(type);
        let source = sourceDay ? form.querySelector(`[name="times[${sourceDay}][${type}]"]`) : null;
        if (!source || !source.value) {
          source = times.find((input) => input.name.endsWith(`[${type}]`) && input.value);
          sourceDay = source?.name.match(/^times\[(\d+)\]/)?.[1] || null;
        }
        if (!source || !source.value || !sourceDay) {
          status.textContent = `コピー元の${type === 'night' ? '夜' : '帰宅後'}の時刻を入力してください。`;
          return;
        }

        const sourceNextDay = type === 'night' ? form.querySelector(`[name="night_next_day[${sourceDay}]"]`) : null;
        for (let day = 1; day <= 7; day += 1) {
          const input = form.querySelector(`[name="times[${day}][${type}]"]`);
          if (!input) continue;
          input.value = source.value;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
          if (type === 'night') {
            const nextDay = form.querySelector(`[name="night_next_day[${day}]"]`);
            if (nextDay) nextDay.checked = !!sourceNextDay?.checked;
          }
        }
        lastSource.set(type, sourceDay);
        const dayName = ['月', '火', '水', '木', '金', '土', '日'][Number(sourceDay) - 1];
        status.textContent = `${dayName}曜の${type === 'night' ? '夜' : '帰宅後'}の時刻を全曜日にコピーしました。保存すると反映されます。`;
      });
    });
  });
})();
