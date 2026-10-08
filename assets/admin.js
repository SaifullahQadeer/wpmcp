document.addEventListener('click', async (event) => {
  const button = event.target.closest('.wpmcp button');
  if (!button) return;
  if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) {
    event.preventDefault();
    return;
  }
  const feedback = document.getElementById('wpmcp-feedback');
  if (button.dataset.reveal) {
    const input = document.getElementById(button.dataset.reveal);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.textContent = show ? 'Hide' : 'Show';
    button.setAttribute('aria-pressed', String(show));
  }
  if (button.dataset.copy) {
    const input = document.getElementById(button.dataset.copy);
    try {
      await navigator.clipboard.writeText(input.value);
      button.textContent = 'Copied';
      feedback.textContent = 'Copied to clipboard.';
      setTimeout(() => { button.textContent = 'Copy'; }, 1800);
    } catch (_) {
      input.type = 'text';
      input.focus();
      input.select();
      feedback.textContent = 'Clipboard unavailable. The value is selected; copy it manually.';
    }
  }
});
