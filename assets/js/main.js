// assets/js/main.js - Main JavaScript File

// DOM Content Loaded
document.addEventListener("DOMContentLoaded", function () {
  // Initialize tooltips
  var tooltipTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="tooltip"]')
  );
  var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // Initialize popovers
  var popoverTriggerList = [].slice.call(
    document.querySelectorAll('[data-bs-toggle="popover"]')
  );
  var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
    return new bootstrap.Popover(popoverTriggerEl);
  });

  // Auto-format currency inputs
  const currencyInputs = document.querySelectorAll(".currency-input");
  currencyInputs.forEach(function (input) {
    input.addEventListener("input", function (e) {
      formatCurrencyInput(e.target);
    });
  });

  // Auto-format number inputs
  const numberInputs = document.querySelectorAll(".number-input");
  numberInputs.forEach(function (input) {
    input.addEventListener("input", function (e) {
      formatNumberInput(e.target);
    });
  });
});

// Utility Functions
function showAlert(message, type = "success") {
  const alertContainer =
    document.getElementById("alert-container") || createAlertContainer();
  const alertId = "alert-" + Date.now();

  const alertHtml = `
        <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="fas fa-${getAlertIcon(type)} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;

  alertContainer.insertAdjacentHTML("afterbegin", alertHtml);

  // Auto-hide after 5 seconds
  setTimeout(function () {
    const alertElement = document.getElementById(alertId);
    if (alertElement) {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(alertElement);
      bsAlert.close();
    }
  }, 5000);
}

function createAlertContainer() {
  const container = document.createElement("div");
  container.id = "alert-container";
  container.className = "position-fixed top-0 end-0 p-3";
  container.style.zIndex = "9999";
  document.body.appendChild(container);
  return container;
}

function getAlertIcon(type) {
  const icons = {
    success: "check-circle",
    danger: "exclamation-triangle",
    warning: "exclamation-triangle",
    info: "info-circle",
  };
  return icons[type] || "info-circle";
}

// Form Validation
function validateForm(formId) {
  const form = document.getElementById(formId);
  const inputs = form.querySelectorAll(
    "input[required], select[required], textarea[required]"
  );
  let isValid = true;

  inputs.forEach(function (input) {
    if (!input.value.trim()) {
      input.classList.add("is-invalid");
      isValid = false;
    } else {
      input.classList.remove("is-invalid");
    }
  });

  return isValid;
}

// Currency Formatting
function formatCurrencyInput(input) {
  let value = input.value.replace(/[^\d]/g, "");
  if (value) {
    input.value = "Rp " + new Intl.NumberFormat("id-ID").format(value);
  }
}

function parseCurrencyValue(value) {
  return parseInt(value.replace(/[^\d]/g, "")) || 0;
}

function formatNumberInput(input) {
  let value = input.value.replace(/[^\d]/g, "");
  if (value) {
    input.value = new Intl.NumberFormat("id-ID").format(value);
  }
}

// AJAX Helper Functions
function sendAjaxRequest(url, method = "GET", data = null) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);

    if (method === "POST") {
      xhr.setRequestHeader("Content-Type", "application/json");
    }

    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4) {
        if (xhr.status === 200) {
          try {
            const response = JSON.parse(xhr.responseText);
            resolve(response);
          } catch (e) {
            resolve(xhr.responseText);
          }
        } else {
          reject(new Error("Request failed with status: " + xhr.status));
        }
      }
    };

    xhr.send(data ? JSON.stringify(data) : null);
  });
}

// Modal Helpers
function openModal(modalId) {
  const modal = new bootstrap.Modal(document.getElementById(modalId));
  modal.show();
}

function closeModal(modalId) {
  const modal = bootstrap.Modal.getInstance(document.getElementById(modalId));
  if (modal) {
    modal.hide();
  }
}

// Confirmation Dialogs
function confirmAction(message, callback) {
  if (confirm(message)) {
    callback();
  }
}

function confirmDelete(
  message = "Apakah Anda yakin ingin menghapus data ini?",
  callback = null
) {
  if (confirm(message)) {
    if (callback) {
      callback();
    }
    return true;
  }
  return false;
}

// Data Table Helpers
function initializeDataTable(tableId, options = {}) {
  const defaultOptions = {
    pageLength: 10,
    responsive: true,
    language: {
      url: "//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json",
    },
  };

  const finalOptions = Object.assign(defaultOptions, options);

  if (typeof $ !== "undefined" && $.fn.DataTable) {
    return $("#" + tableId).DataTable(finalOptions);
  }
}

// Loading Spinner
function showLoading(element) {
  if (typeof element === "string") {
    element = document.getElementById(element);
  }

  if (element) {
    element.innerHTML =
      '<div class="text-center"><div class="spinner"></div> Loading...</div>';
  }
}

function hideLoading(element, content = "") {
  if (typeof element === "string") {
    element = document.getElementById(element);
  }

  if (element) {
    element.innerHTML = content;
  }
}

// Image Preview
function previewImage(input, previewId) {
  const file = input.files[0];
  const preview = document.getElementById(previewId);

  if (file && preview) {
    const reader = new FileReader();
    reader.onload = function (e) {
      preview.src = e.target.result;
      preview.style.display = "block";
    };
    reader.readAsDataURL(file);
  }
}

// Print Function
function printElement(elementId) {
  const element = document.getElementById(elementId);
  if (element) {
    const originalContents = document.body.innerHTML;
    const printContents = element.innerHTML;

    document.body.innerHTML = printContents;
    window.print();
    document.body.innerHTML = originalContents;
    location.reload();
  }
}

// Export Functions
function exportToCSV(data, filename = "export.csv") {
  const csv = convertArrayToCSV(data);
  downloadCSV(csv, filename);
}

function convertArrayToCSV(data) {
  if (!data.length) return "";

  const headers = Object.keys(data[0]);
  const csvContent = [
    headers.join(","),
    ...data.map((row) =>
      headers.map((field) => `"${row[field] || ""}"`).join(",")
    ),
  ].join("\n");

  return csvContent;
}

function downloadCSV(csv, filename) {
  const blob = new Blob([csv], { type: "text/csv" });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  window.URL.revokeObjectURL(url);
}

// Form Serialization
function serializeForm(form) {
  const formData = new FormData(form);
  const data = {};

  for (let [key, value] of formData.entries()) {
    data[key] = value;
  }

  return data;
}

// Local Storage Helpers
function setLocalStorage(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch (e) {
    console.warn("Local storage not available");
  }
}

function getLocalStorage(key, defaultValue = null) {
  try {
    const item = localStorage.getItem(key);
    return item ? JSON.parse(item) : defaultValue;
  } catch (e) {
    return defaultValue;
  }
}

// Date Formatting
function formatDate(date, format = "dd/mm/yyyy") {
  const d = new Date(date);
  const day = String(d.getDate()).padStart(2, "0");
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const year = d.getFullYear();

  switch (format) {
    case "dd/mm/yyyy":
      return `${day}/${month}/${year}`;
    case "yyyy-mm-dd":
      return `${year}-${month}-${day}`;
    case "dd-mm-yyyy":
      return `${day}-${month}-${year}`;
    default:
      return `${day}/${month}/${year}`;
  }
}

// Debounce Function
function debounce(func, wait, immediate) {
  let timeout;
  return function executedFunction(...args) {
    const later = function () {
      timeout = null;
      if (!immediate) func(...args);
    };
    const callNow = immediate && !timeout;
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
    if (callNow) func(...args);
  };
}
