/**
 * SmartStock - Shared stock for product combinations.
 *
 * Back office behaviours of the product panel and of the shared stock dashboard.
 *
 * @author    SmartDev
 * @copyright SmartDev
 * @license   Commercial
 */
(function () {
  'use strict';

  if (window.smartStockAdminLoaded) {
    return;
  }
  window.smartStockAdminLoaded = true;

  /**
   * Parses a JSON payload stored in a data attribute.
   */
  function readJsonAttribute(element, attributeName) {
    try {
      return JSON.parse(element.getAttribute(attributeName) || '{}');
    } catch (parseError) {
      return {};
    }
  }

  /**
   * Strictly parses a signed whole number, returning null for empty or invalid input.
   */
  function parseWholeNumber(rawValue) {
    const trimmedValue = String(rawValue).trim();
    return /^[+-]?\d+$/.test(trimmedValue) ? parseInt(trimmedValue, 10) : null;
  }

  /**
   * Sellable quantity of a combination for a given pool, identical to the server side computation.
   */
  function computeSellableQuantity(poolQuantity, ratio) {
    return Math.floor(poolQuantity / ratio);
  }

  /**
   * Posts an action to the module admin controller and resolves with the decoded JSON response.
   */
  function postAction(ajaxUrl, actionName, parameters) {
    const requestBody = new URLSearchParams();
    Object.keys(parameters).forEach(function (parameterName) {
      requestBody.append(parameterName, parameters[parameterName]);
    });
    return fetch(ajaxUrl + '&ajax=1&action=' + encodeURIComponent(actionName), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body: requestBody,
    }).then(function (response) {
      return response.json().catch(function () {
        return {success: false};
      });
    }).catch(function () {
      return {success: false};
    });
  }

  /**
   * Displays a success or error message in the feedback area of a container.
   */
  function showFeedback(container, message, isSuccess) {
    const feedbackElement = container.querySelector('.js-smartstock-feedback');
    if (!feedbackElement) {
      return;
    }
    feedbackElement.textContent = message;
    feedbackElement.classList.toggle('smartstock-feedback-success', isSuccess);
    feedbackElement.classList.toggle('smartstock-feedback-error', !isSuccess);
  }

  /**
   * Prevents the Enter key from submitting the surrounding product form and triggers the given action instead.
   */
  function bindEnterKey(inputElement, onEnter) {
    inputElement.addEventListener('keydown', function (keyboardEvent) {
      if (keyboardEvent.key === 'Enter') {
        keyboardEvent.preventDefault();
        onEnter();
      }
    });
  }

  /**
   * Shared stock panel displayed on the product page.
   */
  function initializeProductPanel(panel) {
    const ajaxUrl = panel.getAttribute('data-ajax-url');
    const messages = readJsonAttribute(panel, 'data-messages');
    const rowTemplate = panel.querySelector('.js-smartstock-row-template');
    if (!rowTemplate) {
      return;
    }
    const panelElements = {
      activeInput: panel.querySelector('.js-smartstock-active'),
      settingsBlock: panel.querySelector('.js-smartstock-settings'),
      unitInput: panel.querySelector('.js-smartstock-unit'),
      poolInput: panel.querySelector('.js-smartstock-pool'),
      poolHint: panel.querySelector('.js-smartstock-pool-hint'),
      adjustmentBlock: panel.querySelector('.js-smartstock-adjustment-block'),
      adjustmentInput: panel.querySelector('.js-smartstock-adjustment'),
      adjustmentButton: panel.querySelector('.js-smartstock-apply-adjustment'),
      rowsContainer: panel.querySelector('.js-smartstock-rows'),
      suggestButton: panel.querySelector('.js-smartstock-suggest'),
      saveButton: panel.querySelector('.js-smartstock-save'),
    };
    let productState = readJsonAttribute(panel, 'data-state');
    function refreshUnitLabels() {
      const unitLabel = panelElements.unitInput.value.trim() || '-';
      panel.querySelectorAll('.js-smartstock-unit-label').forEach(function (labelElement) {
        labelElement.textContent = unitLabel;
      });
    }
    function refreshPreview() {
      const poolQuantity = parseWholeNumber(panelElements.poolInput.value);
      panelElements.rowsContainer.querySelectorAll('tr').forEach(function (rowElement) {
        const ratio = parseWholeNumber(rowElement.querySelector('.js-smartstock-ratio').value);
        const previewElement = rowElement.querySelector('.js-smartstock-row-preview');
        if (ratio === null || ratio <= 0) {
          previewElement.textContent = messages.independent + ' (' + rowElement.getAttribute('data-quantity') + ')';
          previewElement.classList.add('smartstock-preview-independent');
          return;
        }
        previewElement.classList.remove('smartstock-preview-independent');
        previewElement.textContent = poolQuantity === null ? '-' : String(computeSellableQuantity(poolQuantity, ratio));
      });
    }
    function refreshVisibility() {
      const isEnabled = panelElements.activeInput.checked;
      panelElements.settingsBlock.hidden = !isEnabled;
      panelElements.adjustmentBlock.hidden = !(productState.active && productState.pool_initialized);
      panelElements.poolHint.hidden = productState.pool_initialized;
    }
    function renderState(newState) {
      productState = newState;
      panelElements.activeInput.checked = productState.active;
      panelElements.unitInput.value = productState.unit;
      panelElements.poolInput.value = productState.pool_quantity;
      panelElements.poolInput.setAttribute('data-loaded', productState.pool_initialized ? String(productState.pool_quantity) : '');
      panelElements.adjustmentInput.value = '';
      panelElements.rowsContainer.innerHTML = '';
      productState.combinations.forEach(function (combination) {
        const rowFragment = rowTemplate.content.cloneNode(true);
        const rowElement = rowFragment.querySelector('tr');
        rowElement.setAttribute('data-combination-id', combination.id_product_attribute);
        rowElement.setAttribute('data-suggested-ratio', combination.suggested_ratio);
        rowElement.setAttribute('data-quantity', combination.quantity);
        rowElement.querySelector('.js-smartstock-row-name').textContent = combination.name;
        const ratioInput = rowElement.querySelector('.js-smartstock-ratio');
        ratioInput.value = combination.ratio;
        ratioInput.addEventListener('input', refreshPreview);
        bindEnterKey(ratioInput, saveConfiguration);
        panelElements.rowsContainer.appendChild(rowFragment);
      });
      refreshUnitLabels();
      refreshVisibility();
      refreshPreview();
    }
    function collectRatios() {
      const ratioParameters = {};
      let hasInvalidRatio = false;
      panelElements.rowsContainer.querySelectorAll('tr').forEach(function (rowElement) {
        const ratio = parseWholeNumber(rowElement.querySelector('.js-smartstock-ratio').value || '0');
        if (ratio === null || ratio < 0) {
          hasInvalidRatio = true;
          return;
        }
        ratioParameters['ratios[' + rowElement.getAttribute('data-combination-id') + ']'] = ratio;
      });
      return hasInvalidRatio ? null : ratioParameters;
    }
    function handleResponse(response) {
      panelElements.saveButton.disabled = false;
      panelElements.adjustmentButton.disabled = false;
      if (response.success && response.state) {
        renderState(response.state);
        showFeedback(panel, response.message || messages.saved, true);
        return;
      }
      showFeedback(panel, response.message || messages.error, false);
    }
    function saveConfiguration() {
      const isEnabled = panelElements.activeInput.checked;
      if (!isEnabled && productState.active && !window.confirm(messages.confirmDisable)) {
        return;
      }
      const ratioParameters = collectRatios();
      if (isEnabled && ratioParameters === null) {
        showFeedback(panel, messages.invalidRatio, false);
        return;
      }
      if (isEnabled && parseWholeNumber(panelElements.poolInput.value) === null) {
        showFeedback(panel, messages.invalidQuantity, false);
        return;
      }
      const requestParameters = Object.assign({
        id_product: productState.id_product,
        active: isEnabled ? 1 : 0,
        unit: panelElements.unitInput.value.trim(),
        pool_quantity: isEnabled ? panelElements.poolInput.value.trim() : '',
        pool_loaded_quantity: panelElements.poolInput.getAttribute('data-loaded') || '',
      }, ratioParameters || {});
      panelElements.saveButton.disabled = true;
      postAction(ajaxUrl, 'SaveProduct', requestParameters).then(handleResponse);
    }
    function applyAdjustment() {
      const adjustment = parseWholeNumber(panelElements.adjustmentInput.value);
      if (adjustment === null) {
        showFeedback(panel, messages.invalidQuantity, false);
        return;
      }
      panelElements.adjustmentButton.disabled = true;
      postAction(ajaxUrl, 'UpdatePool', {
        id_product: productState.id_product,
        pool_adjustment: adjustment,
      }).then(handleResponse);
    }
    function applySuggestedRatios() {
      panelElements.rowsContainer.querySelectorAll('tr').forEach(function (rowElement) {
        rowElement.querySelector('.js-smartstock-ratio').value = rowElement.getAttribute('data-suggested-ratio');
      });
      refreshPreview();
    }
    panelElements.activeInput.addEventListener('change', refreshVisibility);
    panelElements.unitInput.addEventListener('input', refreshUnitLabels);
    panelElements.poolInput.addEventListener('input', refreshPreview);
    panelElements.saveButton.addEventListener('click', saveConfiguration);
    panelElements.adjustmentButton.addEventListener('click', applyAdjustment);
    panelElements.suggestButton.addEventListener('click', applySuggestedRatios);
    bindEnterKey(panelElements.unitInput, saveConfiguration);
    bindEnterKey(panelElements.poolInput, saveConfiguration);
    bindEnterKey(panelElements.adjustmentInput, applyAdjustment);
    renderState(productState);
  }

  /**
   * Dashboard listing every shared stock with quick movement and inventory actions.
   */
  function initializeDashboard(dashboard) {
    const ajaxUrl = dashboard.getAttribute('data-ajax-url');
    const messages = readJsonAttribute(dashboard, 'data-messages');
    function renderRow(rowElement, productState) {
      rowElement.querySelector('.js-smartstock-pool-value').textContent = productState.pool_quantity;
      rowElement.querySelector('.js-smartstock-unit-value').textContent = productState.unit;
      rowElement.querySelector('.js-smartstock-inventory').placeholder = productState.pool_quantity;
      const formatsContainer = rowElement.querySelector('.js-smartstock-formats');
      formatsContainer.innerHTML = '';
      productState.combinations.forEach(function (combination) {
        if (combination.ratio <= 0) {
          return;
        }
        const formatElement = document.createElement('span');
        const quantityElement = document.createElement('strong');
        formatElement.className = 'smartstock-format' + (combination.quantity <= 0 ? ' smartstock-format-empty' : '');
        formatElement.appendChild(document.createTextNode(combination.name + ' : '));
        quantityElement.textContent = combination.quantity;
        formatElement.appendChild(quantityElement);
        formatsContainer.appendChild(formatElement);
        formatsContainer.appendChild(document.createTextNode(' '));
      });
    }
    function bindRow(rowElement) {
      const productId = rowElement.getAttribute('data-product-id');
      const adjustmentInput = rowElement.querySelector('.js-smartstock-adjustment');
      const inventoryInput = rowElement.querySelector('.js-smartstock-inventory');
      function submitPoolUpdate(parameterName, inputElement) {
        const quantity = parseWholeNumber(inputElement.value);
        if (quantity === null) {
          showFeedback(dashboard, messages.invalidQuantity, false);
          return;
        }
        const requestParameters = {id_product: productId};
        requestParameters[parameterName] = quantity;
        rowElement.classList.add('smartstock-row-busy');
        postAction(ajaxUrl, 'UpdatePool', requestParameters).then(function (response) {
          rowElement.classList.remove('smartstock-row-busy');
          if (response.success && response.state) {
            inputElement.value = '';
            renderRow(rowElement, response.state);
            showFeedback(dashboard, response.message || messages.saved, true);
            return;
          }
          showFeedback(dashboard, response.message || messages.error, false);
        });
      }
      rowElement.querySelector('.js-smartstock-apply-adjustment').addEventListener('click', function () {
        submitPoolUpdate('pool_adjustment', adjustmentInput);
      });
      rowElement.querySelector('.js-smartstock-apply-inventory').addEventListener('click', function () {
        submitPoolUpdate('pool_quantity', inventoryInput);
      });
      bindEnterKey(adjustmentInput, function () {
        submitPoolUpdate('pool_adjustment', adjustmentInput);
      });
      bindEnterKey(inventoryInput, function () {
        submitPoolUpdate('pool_quantity', inventoryInput);
      });
    }
    dashboard.querySelectorAll('[data-smartstock-row]').forEach(bindRow);
    dashboard.querySelectorAll('.js-smartstock-reconcile-all').forEach(function (reconcileButton) {
      reconcileButton.addEventListener('click', function () {
        reconcileButton.disabled = true;
        postAction(ajaxUrl, 'ReconcileAll', {}).then(function (response) {
          reconcileButton.disabled = false;
          showFeedback(dashboard, response.message || messages.error, Boolean(response.success));
          if (response.success) {
            window.location.reload();
          }
        });
      });
    });
  }

  function initializeAll() {
    document.querySelectorAll('[data-smartstock-product]').forEach(initializeProductPanel);
    document.querySelectorAll('[data-smartstock-dashboard]').forEach(initializeDashboard);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeAll);
  } else {
    initializeAll();
  }
})();
