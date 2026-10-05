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
      const parameterValue = parameters[parameterName];
      if (Array.isArray(parameterValue)) {
        parameterValue.forEach(function (arrayItem) {
          requestBody.append(parameterName + '[]', arrayItem);
        });
        return;
      }
      requestBody.append(parameterName, parameterValue);
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
   * Fills a history table body from the movements of a product state.
   */
  function renderMovements(rowsContainer, rowTemplate, movements, configuration) {
    rowsContainer.innerHTML = '';
    if (!movements.length) {
      const emptyRow = document.createElement('tr');
      const emptyCell = document.createElement('td');
      emptyCell.colSpan = 5;
      emptyCell.className = 'smartstock-muted';
      emptyCell.textContent = configuration.noMovement;
      emptyRow.appendChild(emptyCell);
      rowsContainer.appendChild(emptyRow);
      return;
    }
    movements.forEach(function (movement) {
      const rowFragment = rowTemplate.content.cloneNode(true);
      const reasonElement = document.createElement('span');
      const deltaCell = rowFragment.querySelector('.js-smartstock-history-delta');
      const detailsCell = rowFragment.querySelector('.js-smartstock-history-details');
      reasonElement.className = 'smartstock-reason smartstock-reason-' + movement.reason;
      reasonElement.textContent = configuration.reasons[movement.reason] || movement.reason;
      rowFragment.querySelector('.js-smartstock-history-date').textContent = movement.date_add;
      rowFragment.querySelector('.js-smartstock-history-reason').appendChild(reasonElement);
      deltaCell.textContent = movement.delta_readable;
      deltaCell.classList.add(movement.quantity_delta < 0 ? 'smartstock-negative' : 'smartstock-positive');
      rowFragment.querySelector('.js-smartstock-history-after').textContent = movement.after_readable;
      if (movement.combination_name) {
        detailsCell.appendChild(document.createTextNode(movement.combination_name + ' '));
      }
      if (movement.id_order > 0) {
        const orderLink = document.createElement('a');
        orderLink.href = configuration.orderUrlTemplate.replace(String(configuration.orderUrlPlaceholder), String(movement.id_order));
        orderLink.textContent = configuration.order + ' #' + movement.id_order;
        detailsCell.appendChild(orderLink);
      }
      [movement.employee_name, movement.comment].forEach(function (detailText) {
        if (detailText) {
          const detailElement = document.createElement('small');
          detailElement.className = 'smartstock-muted';
          detailElement.textContent = ' ' + detailText;
          detailsCell.appendChild(detailElement);
        }
      });
      rowsContainer.appendChild(rowFragment);
    });
  }

  /**
   * Shared stock panel displayed on the product page.
   */
  function initializeProductPanel(panel) {
    const ajaxUrl = panel.getAttribute('data-ajax-url');
    const configuration = readJsonAttribute(panel, 'data-configuration');
    const rowTemplate = panel.querySelector('.js-smartstock-row-template');
    const historyTemplate = panel.querySelector('.js-smartstock-history-template');
    if (!rowTemplate || !historyTemplate) {
      return;
    }
    const panelElements = {
      activeInput: panel.querySelector('.js-smartstock-active'),
      settingsBlock: panel.querySelector('.js-smartstock-settings'),
      unitInput: panel.querySelector('.js-smartstock-unit'),
      poolInput: panel.querySelector('.js-smartstock-pool'),
      poolHint: panel.querySelector('.js-smartstock-pool-hint'),
      thresholdInput: panel.querySelector('.js-smartstock-threshold'),
      adjustmentBlock: panel.querySelector('.js-smartstock-adjustment-block'),
      adjustmentInput: panel.querySelector('.js-smartstock-adjustment'),
      adjustmentButton: panel.querySelector('.js-smartstock-apply-adjustment'),
      rowsContainer: panel.querySelector('.js-smartstock-rows'),
      historyBlock: panel.querySelector('.js-smartstock-history-block'),
      historyRows: panel.querySelector('.js-smartstock-history-rows'),
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
          previewElement.textContent = configuration.independent + ' (' + rowElement.getAttribute('data-quantity') + ')';
          previewElement.classList.add('smartstock-preview-independent');
          return;
        }
        previewElement.classList.remove('smartstock-preview-independent');
        previewElement.textContent = poolQuantity === null ? '-' : String(computeSellableQuantity(poolQuantity, ratio));
      });
    }
    function refreshVisibility() {
      const isInitialized = productState.active && productState.pool_initialized;
      panelElements.settingsBlock.hidden = !panelElements.activeInput.checked;
      panelElements.adjustmentBlock.hidden = !isInitialized;
      panelElements.historyBlock.hidden = !isInitialized;
      panelElements.poolHint.hidden = productState.pool_initialized;
    }
    function renderState(newState) {
      productState = newState;
      panelElements.activeInput.checked = productState.active;
      panelElements.unitInput.value = productState.unit || productState.detected_unit || '';
      panelElements.poolInput.value = productState.pool_quantity;
      panelElements.poolInput.setAttribute('data-loaded', productState.pool_initialized ? String(productState.pool_quantity) : '');
      panelElements.thresholdInput.value = productState.alert_threshold > 0 ? productState.alert_threshold : '';
      panelElements.adjustmentInput.value = '';
      panelElements.rowsContainer.innerHTML = '';
      productState.combinations.forEach(function (combination) {
        const rowFragment = rowTemplate.content.cloneNode(true);
        const rowElement = rowFragment.querySelector('tr');
        const ratioInput = rowElement.querySelector('.js-smartstock-ratio');
        rowElement.setAttribute('data-combination-id', combination.id_product_attribute);
        rowElement.setAttribute('data-quantity', combination.quantity);
        rowElement.querySelector('.js-smartstock-row-name').textContent = combination.name;
        ratioInput.value = combination.ratio;
        ratioInput.addEventListener('input', refreshPreview);
        bindEnterKey(ratioInput, saveConfiguration);
        panelElements.rowsContainer.appendChild(rowFragment);
      });
      renderMovements(panelElements.historyRows, historyTemplate, productState.movements || [], configuration);
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
        showFeedback(panel, response.message || configuration.saved, true);
        return;
      }
      showFeedback(panel, response.message || configuration.error, false);
    }
    function saveConfiguration() {
      const isEnabled = panelElements.activeInput.checked;
      if (!isEnabled && productState.active && !window.confirm(configuration.confirmDisable)) {
        return;
      }
      const ratioParameters = collectRatios();
      if (isEnabled && ratioParameters === null) {
        showFeedback(panel, configuration.invalidRatio, false);
        return;
      }
      if (isEnabled && parseWholeNumber(panelElements.poolInput.value) === null) {
        showFeedback(panel, configuration.invalidQuantity, false);
        return;
      }
      const requestParameters = Object.assign({
        id_product: productState.id_product,
        active: isEnabled ? 1 : 0,
        unit: panelElements.unitInput.value.trim(),
        alert_threshold: panelElements.thresholdInput.value.trim(),
        pool_quantity: isEnabled ? panelElements.poolInput.value.trim() : '',
        pool_loaded_quantity: panelElements.poolInput.getAttribute('data-loaded') || '',
      }, ratioParameters || {});
      panelElements.saveButton.disabled = true;
      postAction(ajaxUrl, 'SaveProduct', requestParameters).then(handleResponse);
    }
    function applyAdjustment() {
      const adjustment = parseWholeNumber(panelElements.adjustmentInput.value);
      if (adjustment === null) {
        showFeedback(panel, configuration.invalidQuantity, false);
        return;
      }
      panelElements.adjustmentButton.disabled = true;
      postAction(ajaxUrl, 'UpdatePool', {
        id_product: productState.id_product,
        pool_adjustment: adjustment,
      }).then(handleResponse);
    }
    function applySuggestedRatios() {
      panelElements.suggestButton.disabled = true;
      postAction(ajaxUrl, 'SuggestRatios', {
        id_product: productState.id_product,
        unit: panelElements.unitInput.value.trim(),
      }).then(function (response) {
        panelElements.suggestButton.disabled = false;
        if (!response.success) {
          showFeedback(panel, response.message || configuration.error, false);
          return;
        }
        panelElements.rowsContainer.querySelectorAll('tr').forEach(function (rowElement) {
          const suggestedRatio = response.ratios[rowElement.getAttribute('data-combination-id')];
          if (suggestedRatio !== undefined) {
            rowElement.querySelector('.js-smartstock-ratio').value = suggestedRatio;
          }
        });
        refreshPreview();
      });
    }
    panelElements.activeInput.addEventListener('change', refreshVisibility);
    panelElements.unitInput.addEventListener('input', refreshUnitLabels);
    panelElements.poolInput.addEventListener('input', refreshPreview);
    panelElements.saveButton.addEventListener('click', saveConfiguration);
    panelElements.adjustmentButton.addEventListener('click', applyAdjustment);
    panelElements.suggestButton.addEventListener('click', applySuggestedRatios);
    bindEnterKey(panelElements.unitInput, saveConfiguration);
    bindEnterKey(panelElements.poolInput, saveConfiguration);
    bindEnterKey(panelElements.thresholdInput, saveConfiguration);
    bindEnterKey(panelElements.adjustmentInput, applyAdjustment);
    renderState(productState);
  }

  /**
   * Dashboard: quick movements and inventories, configuration assistant and full resynchronization.
   */
  function initializeDashboard(dashboard) {
    const ajaxUrl = dashboard.getAttribute('data-ajax-url');
    const configuration = readJsonAttribute(dashboard, 'data-configuration');
    function renderRow(rowElement, productState) {
      const formatsContainer = rowElement.querySelector('.js-smartstock-formats');
      rowElement.classList.toggle('smartstock-row-low', productState.is_low);
      rowElement.querySelector('.js-smartstock-pool-value').textContent = productState.pool_readable;
      rowElement.querySelector('.js-smartstock-inventory').placeholder = productState.pool_quantity;
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
          showFeedback(dashboard, configuration.invalidQuantity, false);
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
            showFeedback(dashboard, response.message || configuration.saved, true);
            return;
          }
          showFeedback(dashboard, response.message || configuration.error, false);
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
    function bindActionButton(buttonSelector, actionName, collectParameters) {
      dashboard.querySelectorAll(buttonSelector).forEach(function (actionButton) {
        actionButton.addEventListener('click', function () {
          const requestParameters = collectParameters();
          if (requestParameters === null) {
            return;
          }
          actionButton.disabled = true;
          postAction(ajaxUrl, actionName, requestParameters).then(function (response) {
            actionButton.disabled = false;
            showFeedback(dashboard, response.message || configuration.error, Boolean(response.success));
            if (response.success) {
              window.location.reload();
            }
          });
        });
      });
    }
    dashboard.querySelectorAll('[data-smartstock-row]').forEach(bindRow);
    bindActionButton('.js-smartstock-reconcile-all', 'ReconcileAll', function () {
      return {};
    });
    bindActionButton('.js-smartstock-enable-selected', 'EnableProducts', function () {
      const selectedProductIds = Array.prototype.map.call(dashboard.querySelectorAll('.js-smartstock-candidate:checked'), function (candidateInput) {
        return candidateInput.value;
      });
      return selectedProductIds.length ? {product_ids: selectedProductIds} : null;
    });
    dashboard.querySelectorAll('.js-smartstock-select-all').forEach(function (selectAllInput) {
      selectAllInput.addEventListener('change', function () {
        dashboard.querySelectorAll('.js-smartstock-candidate').forEach(function (candidateInput) {
          candidateInput.checked = selectAllInput.checked;
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
