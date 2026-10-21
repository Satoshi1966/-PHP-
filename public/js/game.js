'use strict';
// DOMへ毎秒書き込まない。10秒通知と時間切れのときだけ表示を変更する。
const turn = document.getElementById('turn');
if (turn) {
  const deadline = performance.now() + Number(turn.dataset.remaining);
  const warning = document.getElementById('timeWarning');
  const answer = document.getElementById('answerArea');
  const timeout = document.getElementById('timeout');
  const key = 'shiritori-noticed:' + turn.dataset.match + ':' + turn.dataset.revision;
  let notified = false;
  try { notified = sessionStorage.getItem(key) === '1'; } catch (_) {}
  const tick = () => {
    const left = deadline - performance.now();
    if (left <= 0) {
      warning.hidden = true;
      answer.hidden = true;
      timeout.hidden = false;
      return;
    }
    if (left <= 10000 && !notified) {
      warning.hidden = false;
      notified = true;
      try { sessionStorage.setItem(key, '1'); } catch (_) {}
    }
    window.setTimeout(tick, Math.min(250, left));
  };
  tick();
}
