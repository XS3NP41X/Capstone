/* Applies a lightweight visual profile before the app becomes interactive. */
(() => {
  'use strict';

  const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  const lowMemory = typeof navigator.deviceMemory === 'number' && navigator.deviceMemory <= 4;
  const fewCores = typeof navigator.hardwareConcurrency === 'number' && navigator.hardwareConcurrency <= 4;
  const saveData = Boolean(connection && connection.saveData);
  const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (lowMemory || fewCores || saveData || reducedMotion) {
    document.documentElement.classList.add('performance-lite');
  }
})();
