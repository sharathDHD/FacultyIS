/**
 * Ported from the legacy project (js/script.js, js/regscript.js) — this is
 * the code identified as the most robust part of the original codebase.
 * IMPORTANT: this is UX convenience only. Auth.php::validateRegistration()
 * enforces the same rules server-side and is the actual source of truth —
 * see FacultyIS-notes.md section 5, "no server-side validation was
 * ever written" was flagged as the #1 issue in the original.
 */

function IsNum(evt) {
  var key = evt.which ? evt.which : evt.keyCode;
  if ((key > 47 && key < 58) || key === 8 || key === 9) return true;
  evt.preventDefault();
  return false;
}

function Ischar(evt) {
  // Allow Unicode letters, spaces, backspace, tab — matches PHP's \p{L} regex.
  var key = evt.which ? evt.which : evt.keyCode;
  // Allow backspace (8), tab (9), space (32)
  if (key === 8 || key === 9 || key === 32) return true;
  // Allow ASCII letters
  if ((key > 64 && key < 91) || (key > 96 && key < 123)) return true;
  // For non-ASCII characters (accented, non-Latin), the browser sends key=0 or
  // the actual Unicode codepoint — we allow these through and let server validate.
  if (key === 0 || key > 127) return true;
  evt.preventDefault();
  return false;
}

// The one piece of the legacy JS that was already production-quality:
// handles Ctrl+A/C/V/S/P, arrow keys, and Firefox keycode quirks.
function AllowOnlyNumbers(evt) {
  var key = evt.which || evt.keyCode;
  if (
    (evt.ctrlKey && [65, 67, 86, 83, 80].indexOf(key) !== -1) ||
    [8, 9, 27, 13, 46, 37, 38, 39, 40].indexOf(key) !== -1
  ) {
    return true;
  }
  if ((key < 48 || key > 57) && (key < 96 || key > 105)) {
    evt.preventDefault();
    return false;
  }
  return true;
}

function validateEmail(value) {
  // Updated regex: TLD now allows 2-20 chars to support modern TLDs (.online, .technology, etc.)
  var pattern = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,20}$/;
  return pattern.test(value);
}

document.addEventListener('DOMContentLoaded', function () {
  var emailInput = document.querySelector('#reg-email');
  if (emailInput) {
    emailInput.addEventListener('input', function () {
      this.classList.toggle('invalid', this.value.length > 0 && !validateEmail(this.value));
    });
  }
});
