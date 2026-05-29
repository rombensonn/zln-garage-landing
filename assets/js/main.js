(function () {
  'use strict';

  var phoneDigitsMin = 10;
  var modal = document.querySelector('[data-modal]');
  var policyModal = document.querySelector('[data-policy-modal]');
  var lastFocus = null;

  function setFormMeta(form) {
    var page = form.querySelector('[name="page_url"]');
    var started = form.querySelector('[name="form_started_at"]');
    if (page) page.value = window.location.href;
    if (started && !started.value) started.value = String(Math.floor(Date.now() / 1000));
  }

  function openModal(service) {
    if (!modal) return;
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    var form = modal.querySelector('form');
    if (form) {
      setFormMeta(form);
      if (service) {
        var select = form.querySelector('[name="service"]');
        if (select) select.value = service;
      }
      var phone = form.querySelector('[name="phone"]');
      if (phone) phone.focus();
    }
  }

  function closeModal(target) {
    if (!target) return;
    target.hidden = true;
    if (!modal || modal.hidden) {
      if (!policyModal || policyModal.hidden) document.body.classList.remove('modal-open');
    }
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  function openPolicy() {
    if (!policyModal) return;
    lastFocus = document.activeElement;
    policyModal.hidden = false;
    document.body.classList.add('modal-open');
    var close = policyModal.querySelector('[data-policy-close]');
    if (close) close.focus();
  }

  document.querySelectorAll('[data-modal-open]').forEach(function (button) {
    button.addEventListener('click', function () {
      openModal(button.getAttribute('data-service'));
    });
  });

  document.querySelectorAll('[data-modal-close]').forEach(function (button) {
    button.addEventListener('click', function () { closeModal(modal); });
  });

  document.querySelectorAll('[data-policy-open]').forEach(function (button) {
    button.addEventListener('click', openPolicy);
  });

  document.querySelectorAll('[data-policy-close]').forEach(function (button) {
    button.addEventListener('click', function () { closeModal(policyModal); });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      if (policyModal && !policyModal.hidden) closeModal(policyModal);
      else if (modal && !modal.hidden) closeModal(modal);
    }
  });

  var menuToggle = document.querySelector('[data-menu-toggle]');
  var nav = document.querySelector('[data-nav]');
  if (menuToggle && nav) {
    menuToggle.addEventListener('click', function () {
      var expanded = menuToggle.getAttribute('aria-expanded') === 'true';
      menuToggle.setAttribute('aria-expanded', String(!expanded));
      nav.classList.toggle('is-open', !expanded);
    });
    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        menuToggle.setAttribute('aria-expanded', 'false');
        nav.classList.remove('is-open');
      });
    });
  }

  document.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      var target = document.querySelector(link.getAttribute('href'));
      if (!target) return;
      event.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  function maskPhone(input) {
    var digits = input.value.replace(/\D/g, '');
    if (digits.charAt(0) === '8') digits = '7' + digits.slice(1);
    if (digits.charAt(0) !== '7') digits = '7' + digits;
    digits = digits.slice(0, 11);
    var value = '+7';
    if (digits.length > 1) value += ' (' + digits.slice(1, 4);
    if (digits.length >= 4) value += ') ' + digits.slice(4, 7);
    if (digits.length >= 7) value += '-' + digits.slice(7, 9);
    if (digits.length >= 9) value += '-' + digits.slice(9, 11);
    input.value = value;
  }

  document.querySelectorAll('input[name="phone"]').forEach(function (input) {
    input.addEventListener('input', function () { maskPhone(input); });
  });

  document.querySelectorAll('[data-form]').forEach(function (form) {
    setFormMeta(form);
    form.addEventListener('focusin', function () { setFormMeta(form); });
    form.addEventListener('submit', submitForm);
  });

  function validate(form) {
    var errors = [];
    var name = form.elements.name ? form.elements.name.value.trim() : '';
    var phone = form.elements.phone ? form.elements.phone.value.trim() : '';
    var service = form.elements.service ? form.elements.service.value.trim() : '';
    var digits = phone.replace(/\D/g, '');

    if (name && name.length < 2) errors.push('Имя должно быть не короче 2 символов.');
    if (!phone || digits.length < phoneDigitsMin) errors.push('Укажите телефон для связи.');
    if (!service) errors.push('Выберите услугу.');

    return errors;
  }

  function setStatus(form, text, type) {
    var status = form.querySelector('.form-status');
    if (!status) return;
    status.textContent = text || '';
    status.classList.remove('is-error', 'is-success');
    if (type) status.classList.add(type);
  }

  function submitForm(event) {
    event.preventDefault();
    var form = event.currentTarget;
    var button = form.querySelector('[type="submit"]');
    var errors = validate(form);
    if (errors.length) {
      setStatus(form, errors[0], 'is-error');
      return;
    }
    if (form.dataset.sending === 'true') return;

    setFormMeta(form);
    form.dataset.sending = 'true';
    if (button) {
      button.disabled = true;
      button.dataset.originalText = button.textContent;
      button.textContent = 'Отправляем...';
    }
    setStatus(form, 'Отправляем...', '');

    fetch('backend/send.php', {
      method: 'POST',
      body: new FormData(form),
      headers: { 'Accept': 'application/json' }
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && data.success) {
          setStatus(form, data.message || 'Заявка отправлена', 'is-success');
          form.reset();
          setFormMeta(form);
        } else {
          setStatus(form, data.message || 'Ошибка отправки', 'is-error');
        }
      })
      .catch(function () {
        setStatus(form, 'Не удалось отправить заявку. Попробуйте позвонить по телефону.', 'is-error');
      })
      .finally(function () {
        form.dataset.sending = 'false';
        if (button) {
          button.disabled = false;
          button.textContent = button.dataset.originalText || 'Отправить заявку';
        }
      });
  }

  document.querySelectorAll('.reveal').forEach(function (item) {
    item.classList.add('is-visible');
  });
})();
