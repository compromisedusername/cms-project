(function () {
  const data = window.SeatMapData || null;
  if (!data) {
    return;
  }

  const onReady = (cb) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', cb);
    } else {
      cb();
    }
  };

  onReady(() => {
    const form = document.querySelector('.rtb-booking-form-form');
    if (!form) { return; }

    const grid = form.querySelector('[data-seat-map]');
    const floor = form.querySelector('[data-seat-map-floor]');
    const hiddenInput = form.querySelector('[data-seat-map-input]');
    const feedback = form.querySelector('[data-seat-map-feedback]');
    const tableField = form.querySelector('[name="rtb-table"]');

    const baseTables = Array.isArray(data.tables) ? data.tables : [];
    if (!grid || !hiddenInput || !feedback || !baseTables.length) {
      return;
    }

    const markerMap = {};
    if (floor) {
      floor.querySelectorAll('[data-seat-map-marker]').forEach((marker) => {
        const id = marker.dataset.tableId;
        if (!id) { return; }
        markerMap[id] = marker;
        marker.addEventListener('click', () => chooseTable(id));
      });
    }

    let selection = data.selectedTable || '';

    const renderTables = () => {
      grid.innerHTML = '';
      baseTables.forEach((table) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'seat-map-button is-available';
        if (selection === table.id) {
          btn.classList.add('is-selected');
        }

        const idSpan = document.createElement('span');
        idSpan.className = 'seat-map-button-id';
        idSpan.textContent = table.id;
        btn.appendChild(idSpan);

        const cap = document.createElement('span');
        cap.className = 'seat-map-button-capacity';
        cap.textContent = (data.strings.seatsLabel || '%s miejsc').replace('%s', table.seats || '?');
        btn.appendChild(cap);

        btn.addEventListener('click', () => chooseTable(table.id));
        grid.appendChild(btn);
      });
    };

    const updateMarkers = () => {
      if (!floor) { return; }
      Object.keys(markerMap).forEach((id) => {
        markerMap[id].classList.toggle('is-selected', selection === id);
      });
    };

    const chooseTable = (tableId) => {
      selection = tableId;
      hiddenInput.value = tableId;
      if (tableField) {
        tableField.value = tableId;
      }
      updateMarkers();
      grid.querySelectorAll('.seat-map-button').forEach((btn) => {
        const label = btn.querySelector('.seat-map-button-id');
        btn.classList.toggle('is-selected', label && label.textContent === tableId);
      });
      setFeedback('');
    };

    const setFeedback = (message) => {
      feedback.textContent = message || '';
      feedback.classList.toggle('is-error', !!message);
    };

    form.addEventListener('submit', (event) => {
      if (!hiddenInput.value) {
        event.preventDefault();
        setFeedback(data.strings.pickTable || 'Wybierz stolik.');
      }
    });

    renderTables();
    updateMarkers();
    if (selection) {
      chooseTable(selection);
    }
  });
})();
