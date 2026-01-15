(function ($) {
  const builder = document.querySelector('[data-seat-map-builder]');
  if (!builder) {
    return;
  }

  const output = builder.querySelector('[data-seat-map-value]');
  const tbody = builder.querySelector('tbody');
  const addBtn = builder.querySelector('[data-seat-map-add]');
  const preview = builder.querySelector('[data-seat-map-admin-preview]');

  const parseValue = function () {
    if (!output || !output.value) {
      return [];
    }
    try {
      const parsed = JSON.parse(output.value);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  };

  let state = parseValue();

  const renderRows = function () {
    tbody.innerHTML = '';
    if (!state.length) {
      addRow();
      return;
    }
    state.forEach(function (item, index) {
      tbody.appendChild(createRow(item, index));
    });
    updatePreview();
  };

  const createRow = function (item, index) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="text" data-field="id" value="${item.id || ''}" /></td>
      <td><input type="number" min="0" data-field="seats" value="${item.seats || 0}" /></td>
      <td><input type="number" min="0" max="100" data-field="x" value="${item.x ?? ''}" /></td>
      <td><input type="number" min="0" max="100" data-field="y" value="${item.y ?? ''}" /></td>
      <td><button type="button" class="button-link" data-action="remove">${SeatMapAdminData.removeRow}</button></td>
    `;

    tr.querySelectorAll('input').forEach(function (input) {
      input.addEventListener('input', function () {
        const field = this.dataset.field;
        state[index][field] = this.value;
        updateOutput();
        updatePreview();
      });
    });

    tr.querySelector('[data-action="remove"]').addEventListener('click', function () {
      if (state.length <= 1 || !confirm(SeatMapAdminData.confirmRemove)) {
        return;
      }
      state.splice(index, 1);
      renderRows();
      updateOutput();
    });

    return tr;
  };

  const addRow = function () {
    state.push({ id: '', seats: 0, x: '', y: '' });
    renderRows();
    updateOutput();
  };

  const updateOutput = function () {
    const normalized = state.map(function (item) {
      return {
        id: String(item.id || '').trim(),
        seats: parseInt(item.seats, 10) || 0,
        x: item.x === '' ? null : parseFloat(item.x),
        y: item.y === '' ? null : parseFloat(item.y)
      };
    }).filter(function (item) {
      return item.id !== '';
    });

    output.value = JSON.stringify(normalized);
  };

  const updatePreview = function () {
    if (!preview) {
      return;
    }
    preview.innerHTML = '';
    state.forEach(function (item) {
      if (item.x === '' || item.y === '' || item.id === '') {
        return;
      }
      const marker = document.createElement('span');
      marker.className = 'seat-map-marker is-available';
      const left = Math.max(0, Math.min(100, parseFloat(item.x)));
      const top = Math.max(0, Math.min(100, parseFloat(item.y)));
      marker.style.left = left + '%';
      marker.style.top = top + '%';
      marker.textContent = item.id;
      preview.appendChild(marker);
    });
  };

  if (addBtn) {
    addBtn.addEventListener('click', function () {
      addRow();
    });
  }

  renderRows();
  updateOutput();

  const bgField = document.querySelector('[data-seat-map-background]');
  if (bgField) {
    const bgInput = bgField.querySelector('[data-seat-map-bg-input]');
    const bgPreview = bgField.querySelector('[data-seat-map-bg-preview]');
    const selectBtn = bgField.querySelector('[data-seat-map-bg-select]');
    const clearBtn = bgField.querySelector('[data-seat-map-bg-clear]');
    let frame;

    const updatePreviewBackground = function (url) {
      if (!preview) {
        return;
      }
      preview.style.backgroundImage = url ? 'url(' + url + ')' : '';
    };

    const updateBgPreview = function (url) {
      bgPreview.innerHTML = '';
      if (!url) {
        updatePreviewBackground('');
        return;
      }
      const img = document.createElement('img');
      img.src = url;
      bgPreview.appendChild(img);
      updatePreviewBackground(url);
    };

    if (selectBtn) {
      selectBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (frame) {
          frame.open();
          return;
        }
        frame = wp.media({
          title: SeatMapAdminData.mediaTitle,
          button: { text: SeatMapAdminData.mediaButton },
          multiple: false
        });
        frame.on('select', function () {
          const attachment = frame.state().get('selection').first().toJSON();
          bgInput.value = attachment.id;
          updateBgPreview(attachment.url);
        });
        frame.open();
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        bgInput.value = '';
        updateBgPreview('');
      });
    }

    if (bgInput.value && bgPreview.querySelector('img')) {
      updatePreviewBackground(bgPreview.querySelector('img').src);
    }
  }
})(jQuery);
