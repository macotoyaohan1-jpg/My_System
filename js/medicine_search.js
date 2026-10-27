document.addEventListener('DOMContentLoaded', () => {
    const availabilityPanel = document.querySelector('#medicineAvailability');
    const selectedMedicineName = document.querySelector('#selectedMedicineName');
    const availabilityStatus = document.querySelector('#medicineAvailabilityStatus');
    const availabilityResults = document.querySelector('#medicineAvailabilityResults');
    const availabilityBack = document.querySelector('#medicineAvailabilityBack');
    const medicineSearchInput = document.querySelector('#medicineSearch');
    const pharmacyExplorer = document.querySelector('.pharmacy-explorer');
    let availabilityRequest;
    let pharmacyViewState = { filter: 'all', position: null, locationError: '' };

    const distanceToPharmacy = (position, pharmacy) => {
        if (pharmacy.latitude === null || pharmacy.latitude === ''
            || pharmacy.longitude === null || pharmacy.longitude === '') {
            return Number.POSITIVE_INFINITY;
        }
        const latitude = Number(pharmacy.latitude);
        const longitude = Number(pharmacy.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return Number.POSITIVE_INFINITY;
        const radians = (degrees) => degrees * Math.PI / 180;
        const latitudeDelta = radians(latitude - position.latitude);
        const longitudeDelta = radians(longitude - position.longitude);
        const arc = Math.sin(latitudeDelta / 2) ** 2
            + Math.cos(radians(position.latitude)) * Math.cos(radians(latitude))
            * Math.sin(longitudeDelta / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(arc), Math.sqrt(1 - arc));
    };

    const applyPharmacyViewState = () => {
        if (!availabilityPanel || availabilityPanel.hidden || !availabilityResults || !availabilityStatus) return;
        const cards = Array.from(availabilityResults.querySelectorAll('.medicine-availability-card'));
        if (pharmacyViewState.filter !== 'nearest') {
            cards.forEach((card) => {
                card.hidden = false;
                const distanceLabel = card.querySelector('[data-availability-distance]');
                if (distanceLabel) distanceLabel.hidden = true;
            });
            if (cards.length > 0) availabilityStatus.textContent = 'Available at:';
            return;
        }
        if (!pharmacyViewState.position) {
            cards.forEach((card) => {
                card.hidden = true;
                const distanceLabel = card.querySelector('[data-availability-distance]');
                if (distanceLabel) distanceLabel.hidden = true;
            });
            availabilityStatus.textContent = pharmacyViewState.locationError
                ? 'Location is unavailable. Allow location access to find the nearest pharmacy.'
                : 'Getting your location to find the nearest pharmacy with this medicine...';
            return;
        }
        const nearest = cards
            .map((card) => ({
                card,
                distance: distanceToPharmacy(pharmacyViewState.position, {
                    latitude: card.dataset.latitude,
                    longitude: card.dataset.longitude
                })
            }))
            .filter((entry) => Number.isFinite(entry.distance))
            .sort((left, right) => left.distance - right.distance)
            [0];
        cards.forEach((card) => { card.hidden = card !== nearest?.card; });
        availabilityStatus.textContent = nearest
            ? 'Nearest pharmacy with this medicine:'
            : 'No nearby pharmacy has this medicine in stock.';
        if (nearest) {
            const distanceLabel = nearest.card.querySelector('[data-availability-distance]');
            if (distanceLabel) {
                distanceLabel.textContent = `${nearest.distance.toFixed(1)} km away`;
                distanceLabel.hidden = false;
            }
        }
    };

    document.addEventListener('medifind:pharmacy-view-state', (event) => {
        pharmacyViewState = { ...pharmacyViewState, ...(event.detail ?? {}) };
        applyPharmacyViewState();
    });

    availabilityBack?.addEventListener('click', () => {
        availabilityRequest?.abort();
        availabilityPanel?.close();
    });

    availabilityPanel?.addEventListener('close', () => {
        availabilityRequest?.abort();
        if (medicineSearchInput && medicineSearchInput.value !== '') {
            medicineSearchInput.value = '';
            medicineSearchInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        pharmacyExplorer?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    const createLineIcon = (type) => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.classList.add('line-icon');

        const paths = type === 'pharmacy'
            ? ['M4 20V9l8-5 8 5v11', 'M8 20v-5h8v5M12 7v4m-2-2h4M3 20h18']
            : ['M7.2 4.5a4.8 4.8 0 0 1 6.8 0l5.5 5.5a4.8 4.8 0 0 1-6.8 6.8l-5.5-5.5a4.8 4.8 0 0 1 0-6.8Z', 'm9.6 13.7 4.8-4.8'];
        paths.forEach((pathData) => {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', pathData);
            svg.append(path);
        });
        return svg;
    };

    const setIconLabel = (element, type, label) => {
        element.replaceChildren(createLineIcon(type), document.createTextNode(label));
    };

    const announceAvailability = (medicine, pharmacies, error = '') => {
        document.dispatchEvent(new CustomEvent('medifind:medicine-availability-loaded', {
            detail: { medicine, pharmacies, error }
        }));
    };

    const showMedicineAvailability = async (medicine) => {
        if (!availabilityPanel || !selectedMedicineName || !availabilityStatus || !availabilityResults) {
            return;
        }

        availabilityRequest?.abort();
        const request = new AbortController();
        availabilityRequest = request;
        availabilityPanel.hidden = false;
        if (!availabilityPanel.open) availabilityPanel.showModal();
        setIconLabel(selectedMedicineName, 'medicine', String(medicine.name ?? 'Medicine'));
        availabilityStatus.textContent = 'Checking pharmacy availability...';
        availabilityResults.replaceChildren();
        announceAvailability(medicine, null);

        try {
            const response = await fetch(
                `search.php?action=availability&medicine_id=${encodeURIComponent(medicine.id)}`,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: request.signal
                }
            );
            const data = await response.json();
            if (!response.ok || data.success !== true || !Array.isArray(data.results)) {
                throw new Error(data.message ?? `Availability search failed (HTTP ${response.status}).`);
            }
            if (availabilityRequest !== request) {
                return;
            }

            announceAvailability(data.medicine ?? medicine, data.results);
            setIconLabel(
                selectedMedicineName,
                'medicine',
                String(data.medicine?.name ?? medicine.name ?? 'Medicine')
            );
            const availableResults = data.results.filter((pharmacy) => (
                ['available', 'low_stock'].includes(String(pharmacy.availability))
                && Number(pharmacy.quantity) > 0
            ));
            if (availableResults.length === 0) {
                availabilityStatus.textContent = 'This medicine is currently unavailable at the listed pharmacies.';
                return;
            }

            availabilityStatus.textContent = 'Available at:';
            availableResults.forEach((pharmacy) => {
                const card = document.createElement('article');
                card.className = 'medicine-availability-card';
                card.dataset.latitude = pharmacy.latitude === null ? '' : String(pharmacy.latitude);
                card.dataset.longitude = pharmacy.longitude === null ? '' : String(pharmacy.longitude);

                const sourceCard = Array.from(document.querySelectorAll('[data-pharmacy-card]'))
                    .find((candidate) => candidate.dataset.pharmacyId === String(pharmacy.pharmacy_id));
                const photo = document.createElement('div');
                photo.className = 'medicine-availability-card-photo';
                const sourceImage = sourceCard?.querySelector('.pharmacy-card-banner img');
                if (sourceImage) {
                    const image = sourceImage.cloneNode(true);
                    image.removeAttribute('loading');
                    image.alt = String(pharmacy.pharmacy_name ?? 'Pharmacy');
                    photo.append(image);
                } else {
                    photo.append(createLineIcon('pharmacy'));
                }
                card.append(photo);

                const content = document.createElement('div');
                content.className = 'medicine-availability-card-content';

                const titleRow = document.createElement('div');
                titleRow.className = 'medicine-availability-card-heading';

                const name = document.createElement('h3');
                name.textContent = String(pharmacy.pharmacy_name ?? 'Pharmacy');
                titleRow.append(name);

                const availability = String(pharmacy.availability ?? 'out_of_stock');
                const status = document.createElement('span');
                status.className = `medicine-stock-status is-${availability.replace(/[^a-z_]/g, '')}`;
                status.textContent = availability === 'available'
                    ? 'Available'
                    : (availability === 'low_stock'
                        ? 'Low Stock'
                        : (availability === 'out_of_stock' ? 'Out of Stock' : 'Status unknown'));
                titleRow.append(status);
                content.append(titleRow);

                const address = document.createElement('p');
                address.textContent = String(pharmacy.address ?? 'Address not provided');
                content.append(address);

                if (pharmacy.phone) {
                    const phone = document.createElement('a');
                    phone.textContent = String(pharmacy.phone);
                    phone.href = `tel:${String(pharmacy.phone).replace(/[^\d+]/g, '')}`;
                    content.append(phone);
                }

                const details = document.createElement('p');
                const quantity = Number(pharmacy.quantity);
                const price = pharmacy.price === null || pharmacy.price === undefined
                    ? 'Price not listed'
                    : new Intl.NumberFormat('en-PH', {
                        style: 'currency',
                        currency: 'PHP'
                    }).format(Number(pharmacy.price));
                details.textContent = `Quantity: ${Number.isFinite(quantity) ? quantity : 0} · ${price}`;
                content.append(details);

                const distanceLabel = document.createElement('p');
                distanceLabel.dataset.availabilityDistance = '';
                distanceLabel.hidden = true;
                content.append(distanceLabel);

                if (
                    ['available', 'low_stock'].includes(availability)
                    && Number.isInteger(quantity)
                    && quantity > 0
                ) {
                    const controls = document.createElement('div');
                    controls.className = 'reservation-controls';

                    const quantityLabel = document.createElement('label');
                    quantityLabel.textContent = 'Quantity';
                    const quantityInput = document.createElement('input');
                    quantityInput.type = 'number';
                    quantityInput.min = '1';
                    quantityInput.max = String(quantity);
                    quantityInput.value = '1';
                    quantityInput.required = true;
                    quantityInput.inputMode = 'numeric';
                    quantityLabel.append(quantityInput);
                    controls.append(quantityLabel);

                    const reserveButton = document.createElement('button');
                    reserveButton.type = 'button';
                    reserveButton.className = 'reserve-medicine-button';
                    reserveButton.textContent = 'Reserve';
                    reserveButton.dataset.reserveMedicine = String(medicine.id);
                    reserveButton.dataset.reservePharmacy = String(pharmacy.pharmacy_id);
                    controls.append(reserveButton);
                    content.append(controls);
                }

                if (pharmacy.updated_at) {
                    const updated = document.createElement('small');
                    updated.textContent = `Updated: ${String(pharmacy.updated_at)}`;
                    content.append(updated);
                }

                const actions = document.createElement('div');
                actions.className = 'medicine-availability-card-actions';
                const locationButton = document.createElement('button');
                locationButton.type = 'button';
                locationButton.className = 'pharmacy-action-button';
                locationButton.textContent = 'View Location';
                const sourceLocationButton = sourceCard?.querySelector('[data-view-location]');
                locationButton.disabled = !sourceLocationButton;
                locationButton.addEventListener('click', () => {
                    if (!sourceLocationButton) return;
                    availabilityPanel.close();
                    window.setTimeout(() => sourceLocationButton.click(), 0);
                });
                actions.append(locationButton);
                content.append(actions);

                card.append(content);
                availabilityResults.append(card);
            });
            applyPharmacyViewState();
        } catch (error) {
            if (error instanceof Error && error.name === 'AbortError') {
                return;
            }
            if (availabilityRequest === request) {
                availabilityStatus.textContent = error instanceof Error
                    ? error.message
                    : 'Pharmacy availability could not be loaded. Please try again.';
                announceAvailability(medicine, null, availabilityStatus.textContent);
            }
        }
    };

    const connectMedicineSearch = (input, results) => {
        if (!input || !results) {
            return;
        }

        let debounceTimer;
        let activeRequest;

        const hideResults = () => {
            results.hidden = true;
            input.setAttribute('aria-expanded', 'false');
        };

        const showMessage = (message, isError = false) => {
            const messageElement = document.createElement('p');
            messageElement.className = isError
                ? 'medicine-search-message is-error'
                : 'medicine-search-message';
            messageElement.setAttribute('role', 'status');
            messageElement.textContent = message;
            results.replaceChildren(messageElement);
            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };

        const showMedicines = (medicines) => {
            results.replaceChildren();
            if (medicines.length === 0) {
                showMessage('No medicine found.');
                return;
            }

            medicines.forEach((medicine) => {
                const result = document.createElement('button');
                result.type = 'button';
                result.className = 'medicine-search-result';
                result.setAttribute('role', 'option');

                const name = document.createElement('strong');
                name.append(
                    createLineIcon('medicine'),
                    document.createTextNode(String(medicine.name ?? 'Medicine'))
                );
                result.append(name);

                [
                    ['Generic name', medicine.generic_name],
                    ['Brand name', medicine.brand_name],
                    ['Dosage', medicine.dosage],
                    ['Form', medicine.form],
                    ['Description', medicine.description]
                ].forEach(([label, value]) => {
                    if (typeof value !== 'string' || value.trim() === '') {
                        return;
                    }

                    const detail = document.createElement('span');
                    detail.textContent = `${label}: ${value}`;
                    result.append(detail);
                });

                result.addEventListener('click', () => {
                    input.value = String(medicine.name ?? '');
                    hideResults();
                    input.dispatchEvent(new CustomEvent('medifind:medicine-selected', {
                        bubbles: true,
                        detail: medicine
                    }));
                    showMedicineAvailability(medicine);
                });
                results.append(result);
            });

            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };

        input.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            activeRequest?.abort();
            availabilityRequest?.abort();
            if (availabilityPanel?.open) availabilityPanel.close();
            if (availabilityPanel) availabilityPanel.hidden = true;
            availabilityResults?.replaceChildren();

            const search = input.value.trim();
            if (search === '') {
                hideResults();
                results.replaceChildren();
                return;
            }

            showMessage('Searching...');
            debounceTimer = window.setTimeout(async () => {
                const request = new AbortController();
                activeRequest = request;

                try {
                    const response = await fetch(
                        `search.php?action=medicines&search=${encodeURIComponent(search)}`,
                        {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' },
                            signal: request.signal
                        }
                    );
                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.message ?? `Search failed (HTTP ${response.status}).`);
                    }
                    if (data.success !== true || !Array.isArray(data.results)) {
                        throw new Error(data.message ?? 'The medicine search returned an unexpected response.');
                    }
                    if (input.value.trim() === search) {
                        showMedicines(data.results);
                    }
                } catch (error) {
                    if (!(error instanceof Error && error.name === 'AbortError')
                        && input.value.trim() === search) {
                        showMessage(
                            error instanceof Error
                                ? error.message
                                : 'Medicine search is unavailable. Please try again.',
                            true
                        );
                    }
                }
            }, 300);
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideResults();
            } else if (event.key === 'Enter') {
                const firstResult = results.querySelector('.medicine-search-result');
                if (firstResult && !results.hidden) {
                    event.preventDefault();
                    firstResult.click();
                }
            }
        });

        document.addEventListener('click', (event) => {
            if (!input.parentElement?.contains(event.target)) {
                hideResults();
            }
        });
    };

    connectMedicineSearch(
        document.querySelector('#medicineSearch'),
        document.querySelector('#medicineResults')
    );
});
