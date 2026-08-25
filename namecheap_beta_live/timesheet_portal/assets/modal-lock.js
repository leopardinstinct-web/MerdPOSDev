(function () {
  'use strict';

  let locked = false;
  let scrollY = 0;
  let bodySnapshot = null;

  const openDialogs = () => Array.from(document.querySelectorAll('dialog[open]'));
  const topDialog = () => {
    const dialogs = openDialogs();
    return dialogs.length ? dialogs[dialogs.length - 1] : null;
  };

  function lockPage() {
    if (locked || !openDialogs().length) return;
    locked = true;
    scrollY = window.scrollY || document.documentElement.scrollTop || 0;
    const scrollbarGap = Math.max(0, window.innerWidth - document.documentElement.clientWidth);

    bodySnapshot = {
      position: document.body.style.position,
      top: document.body.style.top,
      left: document.body.style.left,
      right: document.body.style.right,
      width: document.body.style.width,
      overflow: document.body.style.overflow,
      paddingRight: document.body.style.paddingRight,
      htmlOverflow: document.documentElement.style.overflow,
      htmlOverscrollBehavior: document.documentElement.style.overscrollBehavior,
    };

    document.documentElement.classList.add('modal-scroll-locked');
    document.body.classList.add('modal-scroll-locked');
    document.documentElement.style.overflow = 'hidden';
    document.documentElement.style.overscrollBehavior = 'none';
    document.body.style.position = 'fixed';
    document.body.style.top = `-${scrollY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    document.body.style.overflow = 'hidden';
    if (scrollbarGap > 0) document.body.style.paddingRight = `${scrollbarGap}px`;
  }

  function unlockPage() {
    if (!locked || openDialogs().length) return;
    locked = false;
    document.documentElement.classList.remove('modal-scroll-locked');
    document.body.classList.remove('modal-scroll-locked');

    if (bodySnapshot) {
      document.body.style.position = bodySnapshot.position;
      document.body.style.top = bodySnapshot.top;
      document.body.style.left = bodySnapshot.left;
      document.body.style.right = bodySnapshot.right;
      document.body.style.width = bodySnapshot.width;
      document.body.style.overflow = bodySnapshot.overflow;
      document.body.style.paddingRight = bodySnapshot.paddingRight;
      document.documentElement.style.overflow = bodySnapshot.htmlOverflow;
      document.documentElement.style.overscrollBehavior = bodySnapshot.htmlOverscrollBehavior;
    }

    window.scrollTo({top: scrollY, left: 0, behavior: 'instant'});
    bodySnapshot = null;
  }

  function syncLock() {
    if (openDialogs().length) lockPage();
    else unlockPage();
  }

  function eventInsideTopDialog(event) {
    const dialog = topDialog();
    if (!dialog) return false;
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    return path.includes(dialog) && event.target !== dialog;
  }

  // Prevent mouse-wheel/touch scrolling from leaking through a modal backdrop.
  document.addEventListener('wheel', event => {
    if (!locked || eventInsideTopDialog(event)) return;
    event.preventDefault();
  }, {capture:true, passive:false});

  document.addEventListener('touchmove', event => {
    if (!locked || eventInsideTopDialog(event)) return;
    event.preventDefault();
  }, {capture:true, passive:false});

  // Middle-button autoscroll can bypass ordinary overflow locking in Chromium.
  document.addEventListener('auxclick', event => {
    if (locked && event.button === 1 && !eventInsideTopDialog(event)) event.preventDefault();
  }, true);
  document.addEventListener('mousedown', event => {
    if (locked && event.button === 1 && !eventInsideTopDialog(event)) event.preventDefault();
  }, true);

  // Covers dialogs already in the DOM plus dynamically-created future dialogs.
  const observer = new MutationObserver(records => {
    if (records.some(record => record.type === 'attributes' && record.attributeName === 'open')) syncLock();
  });
  observer.observe(document.documentElement, {subtree:true, attributes:true, attributeFilter:['open']});

  document.addEventListener('close', syncLock, true);
  document.addEventListener('cancel', () => window.setTimeout(syncLock, 0), true);
  window.addEventListener('pageshow', syncLock);
  syncLock();
})();
