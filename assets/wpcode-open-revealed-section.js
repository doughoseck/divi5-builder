/* WPCode snippet: "Open a hidden section from a link"  (JavaScript, Site Wide Footer)
   Divi 5 sections that are hidden until a click Interaction reveals them (tile -> section) cannot be opened by a plain
   link: a URL hash scrolls, it does not fire an Interaction. This clicks the trigger that belongs to the hash and then
   scrolls the opened section under the fixed header. Edit MAP: hash -> [trigger id (data-interaction-trigger), section id].
   Two Divi behaviours handled: the section's reveal animation moves its bounding rect for a moment (so the layout
   offset is used), and Divi's own hash scroll animates html/body towards the still-hidden target = the page top about
   0.7 to 1.8 s after load (so that animation is stopped and the scroll is repeated). */
(function () {
  var MAP = { '#reveal1': ['faqTile1', 'reveal1'], '#reveal2': ['faqTile2', 'reveal2'], '#reveal3': ['faqTile3', 'reveal3'], '#reveal4': ['faqTile4', 'reveal4'], '#reveal5': ['faqTile5', 'reveal5'] };
  var HEADER = '.et_pb_section_1_tb_header';   // the fixed header section (its height is the scroll offset)
  function top(el) { var y = 0; while (el) { y += el.offsetTop; el = el.offsetParent; } return y; }
  function open() {
    var m = MAP[location.hash]; if (!m) return;
    var tile = document.querySelector('[data-interaction-trigger="' + m[0] + '"]'), section = document.getElementById(m[1]);
    if (!tile || !section) return;
    var tries = 0;
    (function go() {
      if (getComputedStyle(section).display === 'none') { tile.click(); }
      if (getComputedStyle(section).display === 'none' && tries++ < 20) { return setTimeout(go, 150); }   // the Interaction runtime may bind a moment after load
      var header = document.querySelector(HEADER);
      function scroll() {
        if (window.jQuery) { jQuery('html, body').stop(true); }
        window.scrollTo(0, Math.max(0, top(section) - (header ? header.offsetHeight : 0) - 10));
      }
      scroll(); setTimeout(scroll, 800); setTimeout(scroll, 2000);
    })();
  }
  if (document.readyState === 'complete') setTimeout(open, 300); else window.addEventListener('load', function () { setTimeout(open, 300); });
  window.addEventListener('hashchange', open);
})();
