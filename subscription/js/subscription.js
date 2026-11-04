(function () {
  // Адрес обработчика заявок на сервере (папка api/ рядом со страницей).
  var ENDPOINT = "api/subscribe.php";

  // Службы доставки. Чтобы добавить новую, допишите строку здесь
  // и в списке delivery_services в api/config.php.
  var DELIVERY_SERVICES = [
    { id: "ozon", name: "Ozon", hint: "Адрес пункта выдачи Ozon можно посмотреть в приложении Ozon, раздел «Пункты выдачи»." },
    { id: "cdek", name: "СДЭК", hint: "Найти ближайший пункт на карте:", mapUrl: "https://www.cdek.ru/ru/offices" }
  ];

  var dialog = document.getElementById("subscribe-dialog");
  var form = document.getElementById("subscribe-form");
  var success = dialog.querySelector(".dialog-success");
  var errorBox = form.querySelector(".form-error");
  var submitBtn = document.getElementById("submit-btn");
  var optionsBox = document.getElementById("delivery-options");
  var hint = document.getElementById("pickup-hint");

  function formatRub(n) {
    return Number(n).toLocaleString("ru-RU");
  }

  function tierPrice(tier) {
    return document.querySelector('.tier[data-tier="' + tier + '"]').dataset.price;
  }

  // Кнопки выбора службы доставки
  DELIVERY_SERVICES.forEach(function (s, i) {
    var label = document.createElement("label");
    label.innerHTML = '<input type="radio" name="delivery_service"><span></span>';
    var input = label.querySelector("input");
    input.value = s.id;
    input.required = true;
    input.checked = i === 0;
    label.querySelector("span").textContent = s.name;
    optionsBox.appendChild(label);
  });

  function updateHint() {
    var id = form.delivery_service.value;
    var s = DELIVERY_SERVICES.find(function (x) { return x.id === id; });
    hint.textContent = s ? s.hint + " " : "";
    if (s && s.mapUrl) {
      var a = document.createElement("a");
      a.href = s.mapUrl;
      a.target = "_blank";
      a.rel = "noopener";
      a.textContent = "открыть карту " + s.name;
      hint.appendChild(a);
    }
  }

  function updateSubmitLabel() {
    submitBtn.textContent = "Перейти к оплате · " + formatRub(tierPrice(form.tier.value)) + " ₽";
  }

  function openDialog(tier) {
    form.reset();
    form.hidden = false;
    success.hidden = true;
    errorBox.hidden = true;
    form.tier.value = tier;
    updateHint();
    updateSubmitLabel();
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
  }

  function closeDialog() {
    if (typeof dialog.close === "function") dialog.close();
    else dialog.removeAttribute("open");
  }

  document.querySelectorAll("[data-subscribe]").forEach(function (btn) {
    btn.addEventListener("click", function () { openDialog(btn.dataset.subscribe); });
  });
  dialog.querySelectorAll("[data-close]").forEach(function (btn) {
    btn.addEventListener("click", closeDialog);
  });
  dialog.addEventListener("click", function (e) {
    if (e.target === dialog) closeDialog();
  });
  form.tier.addEventListener("change", updateSubmitLabel);
  optionsBox.addEventListener("change", updateHint);
  form.addEventListener("input", function () { errorBox.hidden = true; });

  function showError(text) {
    errorBox.textContent = text;
    errorBox.hidden = false;
  }

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (!form.checkValidity()) {
      var bad = form.querySelector(":invalid");
      showError(bad && bad.type === "email"
        ? "Проверьте адрес электронной почты."
        : "Заполните все поля и отметьте оба согласия.");
      if (bad) bad.focus();
      return;
    }
    errorBox.hidden = true;

    var data = Object.fromEntries(new FormData(form).entries());
    data.agree = form.agree.checked;
    data.agree_recurring = form.agree_recurring.checked;

    submitBtn.disabled = true;
    fetch(ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data)
    })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (body) {
          if (!r.ok) throw new Error(body.error || "");
          return body;
        });
      })
      .then(function (body) {
        if (body.confirmation_url) {
          // Переход на страницу оплаты ЮKassa
          window.location.href = body.confirmation_url;
          return;
        }
        form.hidden = true;
        success.hidden = false;
        submitBtn.disabled = false;
      })
      .catch(function (err) {
        showError(err.message || "Не удалось отправить заявку. Попробуйте позже или позвоните нам.");
        submitBtn.disabled = false;
      });
  });

  // Возврат со страницы оплаты: ?paid=<номер подписки>
  if (new URLSearchParams(location.search).has("paid")) {
    document.getElementById("paid-notice").hidden = false;
  }
})();
