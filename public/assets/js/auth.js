/**
 * AlphaForge Auth Page Logic
 * Handles login, register, and forgot-password forms.
 * Depends on window.AlphaForgeAPI (defined in api.js).
 */

'use strict';

// ---------------------------------------------------------------------------
// Password strength calculator
// ---------------------------------------------------------------------------

/**
 * Calculate password strength score and label.
 * @param {string} password
 * @returns {{ score: number, label: string, color: string }}
 */
function calculatePasswordStrength(password) {
  let score = 0;

  if (password.length >= 8)  score += 1;
  if (password.length >= 12) score += 1;
  if (/[A-Z]/.test(password)) score += 1;
  if (/[a-z]/.test(password)) score += 1;
  if (/[0-9]/.test(password)) score += 1;
  if (/[!@#$%^&*]/.test(password)) score += 1;

  let label, color;

  if (score <= 1) {
    label = 'Weak';
    color = '#ef4444';
  } else if (score === 2) {
    label = 'Fair';
    color = '#f59e0b';
  } else if (score === 3) {
    label = 'Good';
    color = '#eab308';
  } else if (score === 4) {
    label = 'Strong';
    color = '#10b981';
  } else {
    label = 'Very Strong';
    color = '#059669';
  }

  return { score, label, color };
}

// ---------------------------------------------------------------------------
// Shared utilities
// ---------------------------------------------------------------------------

/** Basic email format validation. */
function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
}

/** Username: 3-20 chars, alphanumeric + underscore only. */
function isValidUsername(username) {
  return /^[a-zA-Z0-9_]{3,20}$/.test(username.trim());
}

/**
 * Mark an input as invalid and show an error message.
 * @param {HTMLElement} input
 * @param {string} message
 */
function showFieldError(input, message) {
  input.classList.add('error');
  input.classList.remove('valid');

  const wrapper = input.closest('.form-group') || input.parentElement;
  let errorEl = wrapper.querySelector('.field-error');
  if (!errorEl) {
    errorEl = document.createElement('span');
    errorEl.className = 'field-error';
    wrapper.appendChild(errorEl);
  }
  errorEl.textContent = message;
  errorEl.style.display = 'block';
}

/**
 * Clear error state from an input.
 * @param {HTMLElement} input
 */
function clearFieldError(input) {
  input.classList.remove('error');
  input.classList.add('valid');

  const wrapper = input.closest('.form-group') || input.parentElement;
  const errorEl = wrapper.querySelector('.field-error');
  if (errorEl) {
    errorEl.textContent = '';
    errorEl.style.display = 'none';
  }
}

/**
 * Show a form-level error message in a named container.
 * @param {string} containerId
 * @param {string} message
 */
function showFormError(containerId, message) {
  const el = document.getElementById(containerId);
  if (el) {
    el.textContent = message;
    el.style.display = 'block';
  }
}

/** Hide a form-level message container. */
function hideFormMessage(containerId) {
  const el = document.getElementById(containerId);
  if (el) {
    el.textContent = '';
    el.style.display = 'none';
  }
}

/**
 * Set a submit button into loading state.
 * @param {HTMLButtonElement} btn
 * @param {string} loadingText
 */
function setButtonLoading(btn, loadingText = 'Loading…') {
  btn.disabled = true;
  btn.dataset.originalText = btn.textContent;
  btn.textContent = loadingText;
}

/** Restore a submit button from loading state. */
function resetButton(btn) {
  btn.disabled = false;
  if (btn.dataset.originalText) {
    btn.textContent = btn.dataset.originalText;
  }
}

// ---------------------------------------------------------------------------
// Eye / eye-off SVG icons
// ---------------------------------------------------------------------------

const EYE_OPEN_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
  viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
  <circle cx="12" cy="12" r="3"/>
</svg>`;

const EYE_OFF_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
  viewBox="0 0 24 24" fill="none" stroke="currentColor"
  stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8
           a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4
           c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07
           a3 3 0 1 1-4.24-4.24"/>
  <line x1="1" y1="1" x2="23" y2="23"/>
</svg>`;

// ---------------------------------------------------------------------------
// Password show/hide toggle
// ---------------------------------------------------------------------------

/**
 * Attach a show/hide toggle button to a password input.
 * The toggle button must have the attribute data-toggle-password="<inputId>"
 * OR it must be a sibling button with class .password-toggle within the same
 * form-group wrapper.
 *
 * Walks through all .password-toggle buttons on the page.
 */
function initPasswordToggles() {
  // Support two patterns:
  // 1. <button class="password-toggle" data-toggle-password="passwordInputId">
  // 2. Button is a direct sibling of the input inside .input-wrapper / .form-group
  const toggleBtns = document.querySelectorAll('.password-toggle, [data-toggle-password]');

  toggleBtns.forEach((btn) => {
    // Render the initial icon
    btn.innerHTML = EYE_OPEN_SVG;
    btn.setAttribute('type', 'button');
    btn.setAttribute('aria-label', 'Show password');

    btn.addEventListener('click', () => {
      let input = null;

      // Pattern 1: explicit data attribute
      const targetId = btn.dataset.togglePassword;
      if (targetId) {
        input = document.getElementById(targetId);
      }

      // Pattern 2: look for a sibling input[type=password] or input[type=text]
      if (!input) {
        const wrapper = btn.closest('.input-wrapper') || btn.closest('.form-group') || btn.parentElement;
        input = wrapper ? wrapper.querySelector('input[type="password"], input[type="text"]') : null;
      }

      if (!input) return;

      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      btn.innerHTML = isHidden ? EYE_OFF_SVG : EYE_OPEN_SVG;
      btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
  });
}

// ---------------------------------------------------------------------------
// Password strength meter UI
// ---------------------------------------------------------------------------

/**
 * Update the password strength meter element.
 * Looks for #password-strength (or data-strength-for="<inputId>") in the DOM.
 * @param {string} password
 * @param {string} [meterId='password-strength']
 */
function updateStrengthMeter(password, meterId = 'password-strength') {
  const meter = document.getElementById(meterId);
  if (!meter) return;

  if (!password) {
    meter.style.display = 'none';
    return;
  }

  const { score, label, color } = calculatePasswordStrength(password);
  meter.style.display = 'block';

  // Fill bar segments (expects child .strength-bar elements, or we build them)
  const maxScore = 6;
  const fillPct = Math.round((score / maxScore) * 100);

  // Bar element
  let bar = meter.querySelector('.strength-bar-fill');
  if (!bar) {
    const track = document.createElement('div');
    track.className = 'strength-bar-track';
    track.style.cssText = 'height:4px;background:#1e2d4d;border-radius:2px;overflow:hidden;margin-bottom:4px;';
    bar = document.createElement('div');
    bar.className = 'strength-bar-fill';
    bar.style.cssText = 'height:100%;transition:width 0.3s,background 0.3s;border-radius:2px;';
    track.appendChild(bar);
    meter.appendChild(track);
  }

  bar.style.width = `${fillPct}%`;
  bar.style.background = color;

  // Label element
  let labelEl = meter.querySelector('.strength-label');
  if (!labelEl) {
    labelEl = document.createElement('span');
    labelEl.className = 'strength-label';
    labelEl.style.cssText = 'font-size:12px;font-family:Inter,sans-serif;';
    meter.appendChild(labelEl);
  }
  labelEl.textContent = `Password strength: ${label}`;
  labelEl.style.color = color;
}

// ---------------------------------------------------------------------------
// Login form handler
// ---------------------------------------------------------------------------

function initLoginForm() {
  const form = document.getElementById('login-form');
  if (!form) return;

  const emailInput   = form.querySelector('[name="email"], #email');
  const passwordInput = form.querySelector('[name="password"], #password');
  const submitBtn    = form.querySelector('[type="submit"]');

  if (!emailInput || !passwordInput || !submitBtn) return;

  // Clear errors on focus
  [emailInput, passwordInput].forEach((input) => {
    input.addEventListener('focus', () => clearFieldError(input));
    input.addEventListener('input', () => {
      if (input.classList.contains('error')) clearFieldError(input);
    });
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideFormMessage('login-error');

    const email    = emailInput.value.trim();
    const password = passwordInput.value;
    let valid = true;

    if (!email) {
      showFieldError(emailInput, 'Email is required.');
      valid = false;
    } else if (!isValidEmail(email)) {
      showFieldError(emailInput, 'Please enter a valid email address.');
      valid = false;
    } else {
      clearFieldError(emailInput);
    }

    if (!password) {
      showFieldError(passwordInput, 'Password is required.');
      valid = false;
    } else {
      clearFieldError(passwordInput);
    }

    if (!valid) return;

    setButtonLoading(submitBtn, 'Signing in…');

    try {
      const response = await window.AlphaForgeAPI.auth.login(email, password);

      // Store token using the API helper; also honour the legacy key the spec requests
      if (response && response.token) {
        window.AlphaForgeAPI.setToken(response.token);
        localStorage.setItem('af_token', response.token);
      }

      // Redirect to dashboard
      window.location.href = '/views/dashboard/index.html';
    } catch (err) {
      const message = (err && err.message) ? err.message : 'Invalid email or password. Please try again.';
      showFormError('login-error', message);
      resetButton(submitBtn);
    }
  });
}

// ---------------------------------------------------------------------------
// Register form handler
// ---------------------------------------------------------------------------

function initRegisterForm() {
  const form = document.getElementById('register-form');
  if (!form) return;

  const firstNameInput    = form.querySelector('[name="first_name"], #first_name');
  const lastNameInput     = form.querySelector('[name="last_name"], #last_name');
  const emailInput        = form.querySelector('[name="email"], #email');
  const usernameInput     = form.querySelector('[name="username"], #username');
  const passwordInput     = form.querySelector('[name="password"], #password');
  const confirmInput      = form.querySelector('[name="confirm_password"], #confirm_password');
  const termsInput        = form.querySelector('[name="terms"], #terms');
  const submitBtn         = form.querySelector('[type="submit"]');

  // Gracefully handle missing optional fields
  const inputs = [firstNameInput, lastNameInput, emailInput, usernameInput, passwordInput, confirmInput].filter(Boolean);

  // Clear errors on input
  inputs.forEach((input) => {
    input.addEventListener('focus', () => clearFieldError(input));
    input.addEventListener('input', () => {
      if (input.classList.contains('error')) clearFieldError(input);
    });
  });

  // Live: password strength meter
  if (passwordInput) {
    passwordInput.addEventListener('input', () => {
      updateStrengthMeter(passwordInput.value);
    });
  }

  // Live: email format
  if (emailInput) {
    emailInput.addEventListener('blur', () => {
      const val = emailInput.value.trim();
      if (val && !isValidEmail(val)) {
        showFieldError(emailInput, 'Please enter a valid email address.');
      } else if (val) {
        clearFieldError(emailInput);
      }
    });
  }

  // Live: username format
  if (usernameInput) {
    usernameInput.addEventListener('blur', () => {
      const val = usernameInput.value.trim();
      if (val && !isValidUsername(val)) {
        showFieldError(usernameInput, 'Username must be 3-20 characters: letters, numbers, and underscores only.');
      } else if (val) {
        clearFieldError(usernameInput);
      }
    });
  }

  // Live: confirm password match
  if (confirmInput && passwordInput) {
    confirmInput.addEventListener('input', () => {
      if (confirmInput.value && confirmInput.value !== passwordInput.value) {
        showFieldError(confirmInput, 'Passwords do not match.');
      } else if (confirmInput.value) {
        clearFieldError(confirmInput);
      }
    });
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideFormMessage('register-error');

    let valid = true;

    // First name
    if (firstNameInput) {
      if (!firstNameInput.value.trim()) {
        showFieldError(firstNameInput, 'First name is required.');
        valid = false;
      } else {
        clearFieldError(firstNameInput);
      }
    }

    // Last name
    if (lastNameInput) {
      if (!lastNameInput.value.trim()) {
        showFieldError(lastNameInput, 'Last name is required.');
        valid = false;
      } else {
        clearFieldError(lastNameInput);
      }
    }

    // Email
    if (emailInput) {
      const emailVal = emailInput.value.trim();
      if (!emailVal) {
        showFieldError(emailInput, 'Email is required.');
        valid = false;
      } else if (!isValidEmail(emailVal)) {
        showFieldError(emailInput, 'Please enter a valid email address.');
        valid = false;
      } else {
        clearFieldError(emailInput);
      }
    }

    // Username
    if (usernameInput) {
      const uVal = usernameInput.value.trim();
      if (!uVal) {
        showFieldError(usernameInput, 'Username is required.');
        valid = false;
      } else if (!isValidUsername(uVal)) {
        showFieldError(usernameInput, 'Username must be 3-20 characters: letters, numbers, and underscores only.');
        valid = false;
      } else {
        clearFieldError(usernameInput);
      }
    }

    // Password
    if (passwordInput) {
      const pVal = passwordInput.value;
      if (!pVal) {
        showFieldError(passwordInput, 'Password is required.');
        valid = false;
      } else if (pVal.length < 8) {
        showFieldError(passwordInput, 'Password must be at least 8 characters.');
        valid = false;
      } else {
        clearFieldError(passwordInput);
      }
    }

    // Confirm password
    if (confirmInput && passwordInput) {
      if (!confirmInput.value) {
        showFieldError(confirmInput, 'Please confirm your password.');
        valid = false;
      } else if (confirmInput.value !== passwordInput.value) {
        showFieldError(confirmInput, 'Passwords do not match.');
        valid = false;
      } else {
        clearFieldError(confirmInput);
      }
    }

    // Terms
    if (termsInput && !termsInput.checked) {
      showFieldError(termsInput, 'You must accept the terms and conditions.');
      valid = false;
    }

    if (!valid) return;

    setButtonLoading(submitBtn, 'Creating account…');

    const payload = {
      email:            emailInput    ? emailInput.value.trim()    : undefined,
      username:         usernameInput ? usernameInput.value.trim() : undefined,
      password:         passwordInput ? passwordInput.value        : undefined,
      first_name:       firstNameInput ? firstNameInput.value.trim() : undefined,
      last_name:        lastNameInput  ? lastNameInput.value.trim()  : undefined,
    };

    // Remove undefined keys
    Object.keys(payload).forEach((k) => payload[k] === undefined && delete payload[k]);

    try {
      await window.AlphaForgeAPI.auth.register(payload);

      // Hide the form and show success message
      form.style.display = 'none';

      const successEl = document.getElementById('register-success');
      if (successEl) {
        successEl.style.display = 'block';
        // Ensure message is present
        if (!successEl.textContent.trim()) {
          successEl.textContent = 'Please check your email to verify your account.';
        }
      } else {
        // Fallback: insert a success message before the form's parent
        const msg = document.createElement('div');
        msg.id = 'register-success';
        msg.className = 'register-success-message';
        msg.style.cssText = 'text-align:center;padding:24px;color:#10b981;font-family:Inter,sans-serif;';
        msg.textContent = 'Please check your email to verify your account.';
        form.parentElement.insertBefore(msg, form);
      }
    } catch (err) {
      const message = (err && err.message) ? err.message : 'Registration failed. Please try again.';
      showFormError('register-error', message);
      resetButton(submitBtn);
    }
  });
}

// ---------------------------------------------------------------------------
// Forgot password form handler
// ---------------------------------------------------------------------------

function initForgotForm() {
  const form = document.getElementById('forgot-form');
  if (!form) return;

  const emailInput = form.querySelector('[name="email"], #email');
  const submitBtn  = form.querySelector('[type="submit"]');

  if (!emailInput) return;

  emailInput.addEventListener('focus', () => clearFieldError(emailInput));
  emailInput.addEventListener('input', () => {
    if (emailInput.classList.contains('error')) clearFieldError(emailInput);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideFormMessage('forgot-error');
    hideFormMessage('forgot-success');

    const email = emailInput.value.trim();

    if (!email) {
      showFieldError(emailInput, 'Email is required.');
      return;
    }

    if (!isValidEmail(email)) {
      showFieldError(emailInput, 'Please enter a valid email address.');
      return;
    }

    clearFieldError(emailInput);
    setButtonLoading(submitBtn, 'Sending…');

    try {
      await window.AlphaForgeAPI.auth.forgotPassword(email);

      // Hide the form and show success state
      form.style.display = 'none';

      const successEl = document.getElementById('forgot-success');
      if (successEl) {
        successEl.style.display = 'block';
        if (!successEl.textContent.trim()) {
          successEl.textContent = `If an account exists for ${email}, you will receive a password reset link shortly.`;
        }
      } else {
        const msg = document.createElement('div');
        msg.id = 'forgot-success';
        msg.className = 'forgot-success-message';
        msg.style.cssText = 'text-align:center;padding:24px;color:#10b981;font-family:Inter,sans-serif;';
        msg.textContent = `If an account exists for ${email}, you will receive a password reset link shortly.`;
        form.parentElement.insertBefore(msg, form);
      }
    } catch (err) {
      const message = (err && err.message) ? err.message : 'An error occurred. Please try again.';
      showFormError('forgot-error', message);
      resetButton(submitBtn);
    }
  });
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', () => {
  // Wire up show/hide toggles first (shared across all pages)
  initPasswordToggles();

  // Detect which form is present and initialise accordingly
  if (document.getElementById('login-form')) {
    initLoginForm();
  }

  if (document.getElementById('register-form')) {
    initRegisterForm();
  }

  if (document.getElementById('forgot-form')) {
    initForgotForm();
  }
});
