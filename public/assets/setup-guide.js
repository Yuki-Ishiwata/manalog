(() => {
  const guide = document.getElementById('initial-setup-guide');
  if (!guide) return;

  const timetableReady = guide.dataset.timetableReady === '1';
  const notificationTimesReady = guide.dataset.notificationTimesReady === '1';
  let pushReady = guide.dataset.pushReady === '1';

  const refresh = () => {
    const notificationsReady = notificationTimesReady && pushReady;
    const timetableItem = guide.querySelector('[data-setup-item="timetable"]');
    const notificationItem = guide.querySelector('[data-setup-item="notifications"]');
    if (timetableItem) timetableItem.hidden = timetableReady;
    if (notificationItem) notificationItem.hidden = notificationsReady;
    guide.hidden = timetableReady && notificationsReady;
  };

  guide.querySelectorAll('a[href^="#"]').forEach((link) => {
    link.addEventListener('click', () => {
      const target = document.querySelector(link.getAttribute('href'));
      if (!target) return;
      let parent = target.parentElement;
      while (parent) {
        if (parent instanceof HTMLDetailsElement) parent.open = true;
        parent = parent.parentElement;
      }
      if (target instanceof HTMLDetailsElement) target.open = true;
      requestAnimationFrame(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    });
  });

  window.addEventListener('manalog:push-status', (event) => {
    if (event.detail?.enabled || event.detail?.userChanged) pushReady = !!event.detail.enabled;
    refresh();
  });
  refresh();
})();
