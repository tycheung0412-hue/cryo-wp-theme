(function () {
  'use strict';

  var SELECTOR = '.fade-in-left, .fade-in-right, .fade-in-top, .fade-in-bottom';
  var THRESHOLD = 0.15;

  function reveal(entries, observer) {
    for (var i = 0; i < entries.length; i++) {
      if (entries[i].isIntersecting) {
        entries[i].target.classList.add('is-visible');
        observer.unobserve(entries[i].target);
      }
    }
  }

  function init() {
    var targets = document.querySelectorAll(SELECTOR);
    if (!targets.length) return;

    if (!('IntersectionObserver' in window)) {
      for (var i = 0; i < targets.length; i++) {
        targets[i].classList.add('is-visible');
      }
      return;
    }

    var observer = new IntersectionObserver(reveal, {
      rootMargin: '0px',
      threshold: THRESHOLD
    });

    for (var j = 0; j < targets.length; j++) {
      observer.observe(targets[j]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
