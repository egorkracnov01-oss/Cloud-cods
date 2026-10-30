(function () {
  // Куда отправлять заявки. Пустая строка — заявка только выводится в консоль.
  // Укажите адрес обработчика на сервере, например "/api/subscription".
  var ENDPOINT = "";

  // Скидка в зависимости от срока подписки (в месяцах).
  var DISCOUNTS = { 1: 0, 3: 0.05, 6: 0.10, 12: 0.15 };
  var TIER_NAMES = { light: "Лёгкая", medium: "Средняя", high: "Высокая" };

  var currentMonths = 1;

  function formatRub(n) {
    return Math.round(n).toLocaleString("ru-RU");
  }

  function monthsLabel(m) {
    if (m === 1) return "1 месяц";
    if (m >= 2 && m <= 4) return m + " месяца";
    return m + " месяцев";
  }

  function basePrice(tier) {
    var el = document.querySelector('[data-tier="' + tier + '"] .price-value');
    return Number(el.dataset.base);
  }

  function calc(tier, months) {
    var monthly = Math.round(basePrice(tier) * (1 - DISCOUNTS[months]));
    return { monthly: monthly, total: monthly * months };
  }

  // Переключатель срока подписки
  function updatePrices() {
    document.querySelectorAll(".tier").forEach(function (card) {
      var p = calc(card.dataset.tier, currentMonths);
      card.querySelector(".price-value").textContent = formatRub(p.monthly);
      card.querySelector(".price-total").textContent = currentMonths > 1
        ? formatRub(p.total) + " ₽ за " + monthsLabel(currentMonths)
        : "";
    });
  }

  document.querySelectorAll(".period-switch button").forEach(function (btn) {
    btn.addEventListener("click", function () {
      document.querySelectorAll(".period-switch button").forEach(function (b) {
        b.setAttribute("aria-checked", String(b === btn));
      });
      currentMonths = Number(btn.dataset.months);
      updatePrices();
    });
  });

  // Модальное окно оформления
  var dialog = document.getElementById("subscribe-dialog");
  var form = document.getElementById("subscribe-form");
  var success = dialog.querySelector(".dialog-success");
  var summary = document.getElementById("dialog-summary");
  var errorBox = form.querySelector(".form-error");

  function updateSummary() {
    var tier = form.tier.value;
    var months = Number(form.months.value);
    var p = calc(tier, months);
    summary.textContent = TIER_NAMES[tier] + " · " + monthsLabel(months) + " · " + formatRub(p.total) + " ₽";
  }

  function openDialog(tier) {
    form.reset();
    form.hidden = false;
    success.hidden = true;
    errorBox.hidden = true;
    form.tier.value = tier;
    form.months.value = String(currentMonths);
    updateSummary();
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
  form.tier.addEventListener("change", updateSummary);
  form.months.addEventListener("change", updateSummary);

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (!form.checkValidity()) {
      errorBox.textContent = "Заполните обязательные поля: имя, телефон, адрес и согласие.";
      errorBox.hidden = false;
      return;
    }
    errorBox.hidden = true;

    var data = Object.fromEntries(new FormData(form).entries());
    data.months = Number(data.months);
    data.total = Math.round(calc(data.tier, data.months).total);

    var request = ENDPOINT
      ? fetch(ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(data)
        }).then(function (r) { if (!r.ok) throw new Error(r.status); })
      : Promise.resolve(console.log("Заявка на подписку:", data));

    var submitBtn = form.querySelector('[type="submit"]');
    submitBtn.disabled = true;
    request.then(function () {
      form.hidden = true;
      success.hidden = false;
    }).catch(function () {
      errorBox.textContent = "Не удалось отправить заявку. Попробуйте позже или позвоните нам.";
      errorBox.hidden = false;
    }).finally(function () {
      submitBtn.disabled = false;
    });
  });

  updatePrices();
})();
