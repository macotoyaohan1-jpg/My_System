document.addEventListener('DOMContentLoaded', () => {
    const createLocationMarkerIcon = () => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 32 32');
        svg.classList.add('location-symbol-icon');
        const shapes = [
            ['circle', { cx: '16', cy: '16', r: '14.5', class: 'location-icon-ring' }],
            ['path', { d: 'M16 5.5a6 6 0 0 0-6 6c0 4.4 6 10.8 6 10.8s6-6.4 6-10.8a6 6 0 0 0-6-6Z', class: 'location-icon-pin' }],
            ['circle', { cx: '16', cy: '11.5', r: '2.1', class: 'location-icon-center' }],
            ['ellipse', { cx: '16', cy: '23.5', rx: '7.2', ry: '2.7', class: 'location-icon-base' }]
        ];
        shapes.forEach(([tagName, attributes]) => {
            const shape = document.createElementNS('http://www.w3.org/2000/svg', tagName);
            Object.entries(attributes).forEach(([name, value]) => shape.setAttribute(name, value));
            svg.append(shape);
        });
        const marker = document.createElement('span');
        marker.className = 'map-location-marker-body';
        marker.setAttribute('aria-hidden', 'true');
        marker.append(svg);
        return window.L.divIcon({
            className: 'map-location-marker',
            html: marker.outerHTML,
            iconSize: [36, 36],
            iconAnchor: [18, 18],
            popupAnchor: [0, -18]
        });
    };
    const greeting = document.querySelector('#time-greeting');
    let greetingTimeout;
    const updateGreeting = () => {
        if (!greeting) {
            return;
        }
        const now = new Date();
        const hour = now.getHours();
        greeting.textContent = hour < 12
            ? 'Good morning'
            : (hour < 18 ? 'Good afternoon' : 'Good evening');
        const nextChange = new Date(now);
        if (hour < 12) {
            nextChange.setHours(12, 0, 0, 0);
        } else if (hour < 18) {
            nextChange.setHours(18, 0, 0, 0);
        } else {
            nextChange.setDate(nextChange.getDate() + 1);
            nextChange.setHours(0, 0, 0, 0);
        }
        window.clearTimeout(greetingTimeout);
        greetingTimeout = window.setTimeout(updateGreeting, nextChange.getTime() - now.getTime());
    };
    updateGreeting();
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            updateGreeting();
        }
    });
    const dashboard = document.querySelector('.dashboard-shell');
    const pharmacyVisitsList = document.querySelector('#pharmacyVisitsList');
    const pharmacyVisitsEmpty = document.querySelector('#pharmacyVisitsEmpty');
    const sidebar = document.querySelector('.sidebar');
    const indicator = sidebar?.querySelector('.nav-active-indicator');
    const navigationItems = dashboard?.querySelectorAll('.nav-link[data-section], [data-notification-see-all][data-section], [data-notification-back][data-section], [data-dashboard-profile-link][data-section]');
    const featurePanels = document.querySelectorAll('[data-feature-panel]');
    const topbar = document.querySelector('.topbar');
    const notificationToggle = document.querySelector('#notificationPreviewToggle');
    const notificationPreview = document.querySelector('#notificationPreview');
    const closeNotificationPreview = () => {
        if (notificationPreview && notificationToggle) {
            if (typeof notificationPreview.hidePopover === 'function') {
                if (notificationPreview.matches(':popover-open')) notificationPreview.hidePopover();
            } else {
                notificationPreview.hidden = true;
            }
            notificationToggle.setAttribute('aria-expanded', 'false');
        }
    };
    const medicineSearchInput = document.querySelector('#medicineSearch');
    document.querySelector('#compareMedicinePricesButton')?.addEventListener('click', () => {
        medicineSearchInput?.focus({ preventScroll: true });
    });
    const editProfileButton = document.querySelector('#editProfileButton');
    const editProfileForm = document.querySelector('#editProfileForm');
    const profileEditModal = document.querySelector('#profileEditModal');
    const closeProfileEdit = document.querySelector('#closeProfileEdit');
    const profilePhotoInput = document.querySelector('#profilePhotoInput');
    const profileEditPhotoImage = document.querySelector('.profile-edit-photo-image');
    const profilePhotoActionsToggle = document.querySelector('#profilePhotoActionsToggle');
    const profilePhotoActions = document.querySelector('#profilePhotoActions');
    const cancelProfileEdit = document.querySelector('#cancelProfileEdit');
    const settingsPanel = document.querySelector('#settings');
    const themePreference = document.querySelector('#themePreference');
    const pharmacySortPreference = document.querySelector('#defaultPharmacySortPreference');
    const languagePreference = document.querySelector('#languagePreference');
    const settingsSaveStatus = document.querySelector('#settingsSaveStatus');
    const locationPreference = document.querySelector('#locationPreference');
    const useCurrentLocation = document.querySelector('#useCurrentLocation');
    const settingsLocationStatus = document.querySelector('#locationStatus');
    const changePasswordButton = document.querySelector('#changePasswordButton');
    const changePasswordForm = document.querySelector('#changePasswordForm');
    const cancelPasswordChange = document.querySelector('#cancelPasswordChange');
    const pharmacyMapElement = document.querySelector('#pharmacyLocationMap');
    const pharmacyMapStatus = document.querySelector('#pharmacyLocationMapStatus');
    const pharmacyLocationDialog = document.querySelector('#pharmacyLocationDialog');
    const pharmacyDetailsDialog = document.querySelector('#pharmacyDetailsDialog');
    const pharmacyDetailsTitle = document.querySelector('#pharmacyDetailsTitle');
    const pharmacyDetailsAddress = document.querySelector('#pharmacyDetailsAddress');
    const pharmacyDetailsImage = document.querySelector('#pharmacyDetailsImage');
    const pharmacyDetailsDescription = document.querySelector('#pharmacyDetailsDescription');
    const pharmacyDetailsFacts = document.querySelector('#pharmacyDetailsFacts');
    const pharmacyDetailsContact = document.querySelector('#pharmacyDetailsContact');
    const pharmacyDetailsInventory = document.querySelector('#pharmacyDetailsInventory');
    const pharmacyLocationTitle = document.querySelector('#pharmacyLocationTitle');
    const pharmacyLocationAddress = document.querySelector('#pharmacyLocationAddress');
    const pharmacyLocationStatus = document.querySelector('#pharmacyLocationStatus');
    const pharmacyLiveLocationToggle = document.querySelector('#pharmacyLiveLocationToggle');
    const pharmacyMapRecenter = document.querySelector('#pharmacyMapRecenter');
    const pharmacyLiveLocationStatus = document.querySelector('#pharmacyLiveLocationStatus');
    const pharmacySearch = document.querySelector('#pharmacySearch');
    const medicineAvailabilityPanel = document.querySelector('#medicineAvailability');
    const pharmacyCardContainer = document.querySelector('#pharmacyCards');
    const pharmacyExplorer = document.querySelector('.pharmacy-explorer');
    const pharmacyNoResults = document.querySelector('#pharmacyNoResults');
    const pharmacyResultsCount = document.querySelector('#pharmacyResultsCount');
    const pharmacyFilterButtons = document.querySelectorAll('[data-pharmacy-filter]');
    const pharmacyCards = Array.from(document.querySelectorAll('[data-pharmacy-card]'));
    const reservationFilterButtons = document.querySelectorAll('[data-reservation-filter]');
    const reservationCards = Array.from(document.querySelectorAll('[data-reservation-card]'));
    const reservationResultsCount = document.querySelector('#reservationResultsCount');
    const reservationNoResults = document.querySelector('#reservationNoResults');
    let pharmacyMap;
    let selectedPharmacyMarker;
    let pharmacyLiveMarker;
    let pharmacyDistanceLine;
    let pharmacyLocationWatchId = null;
    let selectedPharmacyCoordinates = null;
    let activePharmacyCard = null;
    let activePharmacyDetails = null;
    let activePharmacyDetailsPlaceholder = null;
    let pharmacyPosition;
    let pharmacyLocationError = '';
    let selectedMedicine = null;
    let pharmacyFilter = 'all';
    let locationPreferenceEnabled = true;
    const settingsDefaults = {
        theme: 'system',
        pharmacySort: 'nearest',
        language: 'en',
        medicineAlerts: true,
        pharmacyUpdates: true,
        reservationUpdates: true,
        location: true
    };
    const settingsStorageKey = `medifind-settings-${settingsPanel?.dataset.settingsUserId ?? 'guest'}`;
    let settingsPreferences = { ...settingsDefaults };
    try {
        const storedSettings = JSON.parse(window.localStorage.getItem(settingsStorageKey) ?? '{}');
        if (storedSettings && typeof storedSettings === 'object' && !Array.isArray(storedSettings)) {
            settingsPreferences = {
                ...settingsDefaults,
                ...Object.fromEntries(
                    Object.entries(settingsDefaults).filter(([key, defaultValue]) => (
                        key in storedSettings && typeof storedSettings[key] === typeof defaultValue
                    )).map(([key]) => [key, storedSettings[key]])
                )
            };
        }
    } catch {
        if (settingsSaveStatus) {
            settingsSaveStatus.textContent = 'Saved preferences could not be read from this browser.';
        }
    }
    const applyThemePreference = () => {
        if (dashboard) {
            dashboard.dataset.theme = settingsPreferences.theme === 'system'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : settingsPreferences.theme;
        }
    };
    const saveSettingsPreference = (key, value) => {
        settingsPreferences[key] = value;
        applyThemePreference();
        locationPreferenceEnabled = Boolean(settingsPreferences.location);
        if (locationPreference) {
            locationPreference.checked = locationPreferenceEnabled;
        }
        if (useCurrentLocation) {
            useCurrentLocation.disabled = !locationPreferenceEnabled;
        }
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (settingsPreferences.theme === 'system') {
                applyThemePreference();
            }
        });
        try {
            window.localStorage.setItem(settingsStorageKey, JSON.stringify(settingsPreferences));
            if (settingsSaveStatus) {
                settingsSaveStatus.textContent = 'Preferences saved on this device.';
            }
        } catch {
            if (settingsSaveStatus) {
                settingsSaveStatus.textContent = 'Could not save preferences. Check this browser’s storage settings.';
            }
        }
    };
    if (!['light', 'dark', 'system'].includes(settingsPreferences.theme)) {
        settingsPreferences.theme = 'system';
    }
    if (!['nearest', 'available', 'alphabetical'].includes(settingsPreferences.pharmacySort)) {
        settingsPreferences.pharmacySort = 'nearest';
    }
    if (!['en', 'fil'].includes(settingsPreferences.language)) {
        settingsPreferences.language = 'en';
    }
    if (themePreference) {
        themePreference.value = settingsPreferences.theme;
    }
    if (pharmacySortPreference) {
        pharmacySortPreference.value = settingsPreferences.pharmacySort;
    }
    if (languagePreference) {
        languagePreference.value = settingsPreferences.language;
    }
    document.documentElement.lang = settingsPreferences.language;
    document.querySelectorAll('[data-setting]').forEach((control) => {
        const settingKey = control.dataset.setting;
        if (settingKey === 'theme') {
            return;
        }
        if (control instanceof HTMLInputElement && settingKey in settingsPreferences) {
            control.checked = Boolean(settingsPreferences[settingKey]);
        }
    });
    locationPreferenceEnabled = Boolean(settingsPreferences.location);
    applyThemePreference();
    if (useCurrentLocation) {
        useCurrentLocation.disabled = !locationPreferenceEnabled;
    }
    const clearSelectedMedicine = () => {
        selectedMedicine = null;
        if (medicineAvailabilityPanel) {
            if (medicineAvailabilityPanel.open) medicineAvailabilityPanel.close();
            medicineAvailabilityPanel.hidden = true;
        }
        updatePharmacyResults();
    };
    const distanceInKilometers = (fromLatitude, fromLongitude, toLatitude, toLongitude) => {
        const radians = (degrees) => degrees * Math.PI / 180;
        const latitudeDelta = radians(toLatitude - fromLatitude);
        const longitudeDelta = radians(toLongitude - fromLongitude);
        const arc = Math.sin(latitudeDelta / 2) ** 2
            + Math.cos(radians(fromLatitude))
            * Math.cos(radians(toLatitude))
            * Math.sin(longitudeDelta / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(arc), Math.sqrt(1 - arc));
    };
    const updatePharmacyResults = () => {
        if (pharmacyExplorer) {
            pharmacyExplorer.hidden = Boolean(selectedMedicine);
        }
        const searchTerm = pharmacySearch?.value.trim().toLocaleLowerCase() ?? '';
        const visibleCards = pharmacyCards.filter((card) => {
            const matchesSearch = (card.dataset.search ?? '').toLocaleLowerCase().includes(searchTerm);
            const matchesFilter = pharmacyFilter !== 'with-medicine'
                || card.dataset.hasMedicine === 'true';
            return matchesSearch && matchesFilter;
        });
        const sortMode = pharmacyFilter === 'nearest'
            ? 'nearest'
            : settingsPreferences.pharmacySort;
        const compareNames = (left, right) => (
            (left.dataset.pharmacyName ?? '').localeCompare(right.dataset.pharmacyName ?? '', undefined, {
                sensitivity: 'base'
            })
        );
        visibleCards.sort((left, right) => {
            if (sortMode === 'available') {
                const availabilityOrder = Number(right.dataset.hasMedicine === 'true')
                    - Number(left.dataset.hasMedicine === 'true');
                return availabilityOrder || compareNames(left, right);
            }
            if (sortMode === 'nearest' && pharmacyPosition) {
                const distance = (card) => card.dataset.hasCoordinates === 'true'
                    ? distanceInKilometers(
                        pharmacyPosition.latitude,
                        pharmacyPosition.longitude,
                        Number(card.dataset.latitude),
                        Number(card.dataset.longitude)
                    )
                    : Number.POSITIVE_INFINITY;
                const leftDistance = distance(left);
                const rightDistance = distance(right);
                if (leftDistance !== rightDistance) {
                    const distanceOrder = leftDistance - rightDistance;
                    if (!Number.isNaN(distanceOrder)) return distanceOrder;
                }
            }
            return compareNames(left, right);
        });
        if (pharmacyFilter === 'nearest') {
            const nearestCard = pharmacyPosition
                ? visibleCards.find((card) => card.dataset.hasCoordinates === 'true')
                : null;
            if (nearestCard) {
                visibleCards.splice(0, visibleCards.length, nearestCard);
            } else {
                visibleCards.splice(0);
            }
        }
        visibleCards.forEach((card) => {
            card.hidden = false;
            if (pharmacyCardContainer && card.parentElement === pharmacyCardContainer) {
                pharmacyCardContainer.append(card);
            }
        });
        pharmacyCards.forEach((card) => {
            if (!visibleCards.includes(card)) {
                card.hidden = true;
            }
            const distanceLabel = card.querySelector('[data-pharmacy-distance]');
            if (distanceLabel) {
                if (pharmacyPosition && card.dataset.hasCoordinates === 'true') {
                    const distance = distanceInKilometers(
                        pharmacyPosition.latitude,
                        pharmacyPosition.longitude,
                        Number(card.dataset.latitude),
                        Number(card.dataset.longitude)
                    );
                    distanceLabel.textContent = `${distance.toFixed(1)} km away`;
                } else {
                    distanceLabel.textContent = 'Distance unavailable';
                }
            }
        });
        if (pharmacyResultsCount) {
            pharmacyResultsCount.textContent = `${visibleCards.length} ${visibleCards.length === 1 ? 'pharmacy' : 'pharmacies'}`;
        }
        if (pharmacyNoResults) {
            pharmacyNoResults.hidden = visibleCards.length > 0;
        }
        publishPharmacyViewState();
    };
    const publishPharmacyViewState = () => {
        document.dispatchEvent(new CustomEvent('medifind:pharmacy-view-state', {
            detail: {
                filter: pharmacyFilter,
                position: pharmacyPosition,
                selectedMedicine: Boolean(selectedMedicine),
                locationError: pharmacyLocationError
            }
        }));
    };
    const setPharmacyFilter = (filter) => {
        pharmacyFilter = filter;
        pharmacyFilterButtons.forEach((button) => {
            const isActive = button.dataset.pharmacyFilter === filter;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });
        publishPharmacyViewState();
    };
    const shouldShowPharmacyLocationStatus = () => pharmacyFilter === 'nearest'
        || (dashboard?.dataset.activeSection === 'pharmacies'
            && settingsPreferences.pharmacySort === 'nearest');
    const requestPharmacyLocation = () => {
        if (!locationPreferenceEnabled) {
            pharmacyLocationError = 'Turn on Location Services in Settings to sort by distance.';
            if (pharmacyLocationStatus && shouldShowPharmacyLocationStatus()) {
                pharmacyLocationStatus.textContent = pharmacyLocationError;
                pharmacyLocationStatus.hidden = false;
            }
            if (settingsLocationStatus) {
                settingsLocationStatus.textContent = 'Turn on Location Services before requesting your location.';
            }
            publishPharmacyViewState();
            return;
        }
        if (!navigator.geolocation) {
            pharmacyLocationError = 'Location services are not available in this browser.';
            if (pharmacyLocationStatus && shouldShowPharmacyLocationStatus()) {
                pharmacyLocationStatus.textContent = pharmacyLocationError;
                pharmacyLocationStatus.hidden = false;
            }
            if (settingsLocationStatus) {
                settingsLocationStatus.textContent = 'Location services are not available in this browser.';
            }
            if (useCurrentLocation) {
                useCurrentLocation.disabled = !locationPreferenceEnabled;
            }
            publishPharmacyViewState();
            return;
        }
        if (pharmacyPosition) {
            if (settingsLocationStatus) {
                settingsLocationStatus.textContent = 'Location access is available for this session.';
            }
            if (useCurrentLocation) {
                useCurrentLocation.disabled = false;
            }
            updatePharmacyResults();
            return;
        }
        if (pharmacyLocationStatus && shouldShowPharmacyLocationStatus()) {
            pharmacyLocationStatus.textContent = 'Getting your location to sort by distance…';
            pharmacyLocationStatus.hidden = false;
        }
        if (typeof window.getMyLocation !== 'function') {
            const message = 'The location feature could not be loaded. Please refresh and try again.';
            pharmacyLocationError = message;
            if (pharmacyLocationStatus && shouldShowPharmacyLocationStatus()) {
                pharmacyLocationStatus.textContent = message;
                pharmacyLocationStatus.hidden = false;
            }
            if (settingsLocationStatus) {
                settingsLocationStatus.textContent = message;
            }
            if (useCurrentLocation) {
                useCurrentLocation.disabled = !locationPreferenceEnabled;
            }
            publishPharmacyViewState();
            return;
        }
        window.getMyLocation(
            (coordinates) => {
                pharmacyLocationError = '';
                pharmacyPosition = {
                    latitude: coordinates.latitude,
                    longitude: coordinates.longitude
                };
                if (settingsLocationStatus) {
                    settingsLocationStatus.textContent = 'Location access is available for this session.';
                }
                if (useCurrentLocation) {
                    useCurrentLocation.disabled = false;
                }
                if (pharmacyLocationStatus) {
                    pharmacyLocationStatus.hidden = true;
                }
                updatePharmacyResults();
            },
            (error) => {
                pharmacyLocationError = error.message;
                if (useCurrentLocation) {
                    useCurrentLocation.disabled = !locationPreferenceEnabled;
                }
                if (pharmacyLocationStatus && shouldShowPharmacyLocationStatus()) {
                    pharmacyLocationStatus.textContent = error.message;
                    pharmacyLocationStatus.hidden = false;
                }
                if (settingsLocationStatus) {
                    settingsLocationStatus.textContent = error.message;
                }
                publishPharmacyViewState();
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    };
    const requestMedicineSearchLocation = () => {
        if (!locationPreferenceEnabled) {
            pharmacyLocationError = 'Turn on Location Services in Settings to find the nearest pharmacy.';
            updatePharmacyResults();
            return;
        }
        if (pharmacyPosition) {
            updatePharmacyResults();
            return;
        }
        if (!navigator.geolocation) {
            pharmacyLocationError = 'Location services are not available in this browser.';
            updatePharmacyResults();
            return;
        }

        pharmacyLocationError = '';
        publishPharmacyViewState();
        navigator.geolocation.getCurrentPosition(
            (position) => {
                pharmacyPosition = {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude
                };
                pharmacyLocationError = '';
                updatePharmacyResults();
            },
            (error) => {
                pharmacyLocationError = error.message || 'Your location could not be determined.';
                updatePharmacyResults();
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    };
    const showPharmacyLocation = (button) => {
        if (!pharmacyLocationDialog) return;
        pharmacyLocationTitle.textContent = button.dataset.pharmacyName || 'Pharmacy';
        pharmacyLocationAddress.textContent = button.dataset.pharmacyAddress || 'Address not provided';
        const latitude = Number(button.dataset.pharmacyLatitude);
        const longitude = Number(button.dataset.pharmacyLongitude);
        const hasCoordinates = button.dataset.pharmacyLatitude !== ''
            && button.dataset.pharmacyLongitude !== ''
            && Number.isFinite(latitude) && latitude >= -90 && latitude <= 90
            && Number.isFinite(longitude) && longitude >= -180 && longitude <= 180;
        pharmacyLocationDialog.showModal();
        if (!hasCoordinates) {
            selectedPharmacyCoordinates = null;
            if (pharmacyLiveLocationToggle) pharmacyLiveLocationToggle.disabled = true;
            if (pharmacyMapRecenter) pharmacyMapRecenter.disabled = true;
            pharmacyMapElement.hidden = true;
            pharmacyMapStatus.textContent = 'Map coordinates are not available for this pharmacy yet.';
            pharmacyMapStatus.hidden = false;
            return;
        }
        if (!window.L) {
            if (pharmacyLiveLocationToggle) pharmacyLiveLocationToggle.disabled = true;
            if (pharmacyMapRecenter) pharmacyMapRecenter.disabled = true;
            pharmacyMapElement.hidden = true;
            pharmacyMapStatus.textContent = 'The map could not be loaded. You can still view the pharmacy details.';
            pharmacyMapStatus.hidden = false;
            return;
        }
        pharmacyMapElement.hidden = false;
        if (pharmacyLiveLocationToggle) {
            pharmacyLiveLocationToggle.disabled = !locationPreferenceEnabled;
        }
        if (pharmacyMapRecenter) pharmacyMapRecenter.disabled = false;
        pharmacyMapStatus.hidden = true;
        selectedPharmacyCoordinates = { latitude, longitude };
        if (pharmacyLiveMarker && pharmacyMap) pharmacyMap.removeLayer(pharmacyLiveMarker);
        if (pharmacyDistanceLine && pharmacyMap) pharmacyMap.removeLayer(pharmacyDistanceLine);
        pharmacyLiveMarker = null;
        pharmacyDistanceLine = null;
        if (pharmacyLiveLocationStatus) {
            pharmacyLiveLocationStatus.textContent = 'Map centered on the selected pharmacy. Start live location to see your position and distance.';
        }
        if (!pharmacyMap) {
            pharmacyMap = window.L.map(pharmacyMapElement, {
                scrollWheelZoom: true,
                zoomControl: true,
                preferCanvas: true
            });
            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(pharmacyMap);
        }
        if (selectedPharmacyMarker) pharmacyMap.removeLayer(selectedPharmacyMarker);
        const popup = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = pharmacyLocationTitle.textContent;
        const address = document.createElement('p');
        address.textContent = pharmacyLocationAddress.textContent;
        popup.append(name, address);
        selectedPharmacyMarker = window.L.marker([latitude, longitude], {
            icon: createLocationMarkerIcon()
        }).bindPopup(popup).addTo(pharmacyMap);
        pharmacyMap.setView([latitude, longitude], 15);
        selectedPharmacyMarker.openPopup();
        window.requestAnimationFrame(() => pharmacyMap.invalidateSize());
    };
    const showPharmacyDetails = (button) => {
        const card = button.closest('[data-pharmacy-card]');
        if (!pharmacyDetailsDialog || !card) return;
        recordPharmacyVisit(card);
        activePharmacyCard = card;
        pharmacyDetailsTitle.textContent = card.querySelector('.pharmacy-card-title strong')?.textContent?.trim() || 'Pharmacy';
        pharmacyDetailsAddress.textContent = card.querySelector('.pharmacy-card-title > span')?.textContent?.trim() || 'Address not provided';
        const image = card.querySelector('.pharmacy-card-banner img, .pharmacy-card-symbol');
        if (image) {
            const imageCopy = image.cloneNode(true);
            if (imageCopy instanceof HTMLImageElement) imageCopy.removeAttribute('loading');
            pharmacyDetailsImage.replaceChildren(imageCopy);
        } else {
            pharmacyDetailsImage.replaceChildren();
        }
        const description = card.querySelector('.pharmacy-card-description')?.textContent?.trim() ?? '';
        pharmacyDetailsDescription.textContent = description;
        pharmacyDetailsDescription.hidden = description === '';
        pharmacyDetailsFacts.replaceChildren(
            ...Array.from(card.querySelectorAll('.pharmacy-card-facts > span'), (fact) => fact.cloneNode(true))
        );
        const phone = card.querySelector('.pharmacy-contact-row a');
        if (phone) {
            pharmacyDetailsContact.replaceChildren(phone.cloneNode(true));
            pharmacyDetailsContact.hidden = false;
        } else {
            pharmacyDetailsContact.replaceChildren();
            pharmacyDetailsContact.hidden = true;
        }
        const medicineDetails = card.querySelector('[data-pharmacy-details]');
        if (medicineDetails && pharmacyDetailsInventory) {
            activePharmacyDetails = medicineDetails;
            activePharmacyDetailsPlaceholder = document.createComment('pharmacy inventory location');
            medicineDetails.before(activePharmacyDetailsPlaceholder);
            medicineDetails.hidden = false;
            pharmacyDetailsInventory.replaceChildren(medicineDetails);
        }
        pharmacyDetailsDialog.showModal();
    };
    const recordPharmacyVisit = (card) => {
        const pharmacyId = card.dataset.pharmacyId;
        const csrfToken = dashboard?.dataset.pharmacyVisitCsrf ?? '';
        if (!pharmacyId || !csrfToken) return;

        fetch('pharmacy_visits.php?action=record', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                Accept: 'application/json'
            },
            body: new URLSearchParams({ pharmacy_id: pharmacyId, csrf_token: csrfToken })
        })
            .then(async (response) => ({ response, data: await response.json() }))
            .then(({ response, data }) => {
                if (response.ok && data.success === true) {
                    renderRecentPharmacyVisit(card, String(data.visited_at ?? 'Just now'));
                }
            })
            .catch(() => {});
    };
    const renderRecentPharmacyVisit = (card, visitedAt) => {
        if (!pharmacyVisitsList) return;
        const pharmacyId = card.dataset.pharmacyId;
        const pharmacyName = card.querySelector('.pharmacy-card-title strong')?.textContent?.trim() || 'Pharmacy';
        const pharmacyAddress = card.querySelector('.pharmacy-card-title > span')?.textContent?.trim() || 'Location not provided';
        let row = Array.from(pharmacyVisitsList.querySelectorAll('[data-pharmacy-visit-id]'))
            .find((visit) => visit.dataset.pharmacyVisitId === pharmacyId);
        if (!row) row = document.createElement('article');
        row.className = 'pharmacy-row';
        row.dataset.pharmacyVisitId = pharmacyId;

        const symbol = document.createElement('span');
        symbol.className = 'pharmacy-symbol';
        symbol.setAttribute('aria-hidden', 'true');
        const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        icon.setAttribute('viewBox', '0 0 24 24');
        icon.classList.add('line-icon');
        const iconPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        iconPath.setAttribute('d', 'M4 20V9l8-5 8 5v11M8 20v-5h8v5M12 7v4m-2-2h4M3 20h18');
        icon.append(iconPath);
        symbol.append(icon);

        const info = document.createElement('div');
        info.className = 'pharmacy-info';
        const title = document.createElement('h3');
        title.textContent = pharmacyName;
        const address = document.createElement('p');
        address.textContent = pharmacyAddress;
        const time = document.createElement('p');
        time.className = 'reservation-date';
        time.textContent = visitedAt;
        info.append(title, address, time);
        row.replaceChildren(symbol, info);
        pharmacyVisitsList.prepend(row);
        pharmacyVisitsList.hidden = false;
        if (pharmacyVisitsEmpty) pharmacyVisitsEmpty.hidden = true;

        Array.from(pharmacyVisitsList.querySelectorAll('[data-pharmacy-visit-id]'))
            .slice(5)
            .forEach((oldVisit) => oldVisit.remove());
    };
    const stopPharmacyLocationWatch = (message = 'Live location sharing stopped.') => {
        const wasWatching = pharmacyLocationWatchId !== null;
        if (wasWatching && navigator.geolocation) {
            navigator.geolocation.clearWatch(pharmacyLocationWatchId);
        }
        pharmacyLocationWatchId = null;
        if (pharmacyMap && pharmacyLiveMarker) pharmacyMap.removeLayer(pharmacyLiveMarker);
        if (pharmacyMap && pharmacyDistanceLine) pharmacyMap.removeLayer(pharmacyDistanceLine);
        pharmacyLiveMarker = null;
        pharmacyDistanceLine = null;
        pharmacyLiveLocationToggle?.setAttribute('aria-pressed', 'false');
        if (pharmacyLiveLocationToggle) pharmacyLiveLocationToggle.textContent = 'Start live location';
        if (pharmacyLiveLocationStatus) pharmacyLiveLocationStatus.textContent = message;
        if (wasWatching) {
            pharmacyPosition = null;
            updatePharmacyResults();
        }
    };
    const startPharmacyLocationWatch = () => {
        if (!locationPreferenceEnabled) {
            if (pharmacyLiveLocationStatus) {
                pharmacyLiveLocationStatus.textContent = 'Enable Location Services in Settings before sharing your live location.';
            }
            return;
        }
        if (!navigator.geolocation || !selectedPharmacyCoordinates || !pharmacyMap) {
            if (pharmacyLiveLocationStatus) {
                pharmacyLiveLocationStatus.textContent = 'Live location is not available in this browser.';
            }
            return;
        }
        if (pharmacyLocationWatchId !== null) {
            stopPharmacyLocationWatch();
            return;
        }
        pharmacyLiveLocationToggle?.setAttribute('aria-pressed', 'true');
        if (pharmacyLiveLocationToggle) pharmacyLiveLocationToggle.textContent = 'Stop live location';
        if (pharmacyLiveLocationStatus) {
            pharmacyLiveLocationStatus.textContent = 'Waiting for a GPS location update…';
        }
        pharmacyLocationWatchId = navigator.geolocation.watchPosition(
            (position) => {
                if (pharmacyLocationWatchId === null || !selectedPharmacyCoordinates) return;
                const { latitude, longitude } = position.coords;
                pharmacyPosition = { latitude, longitude };
                updatePharmacyResults();
                if (!pharmacyLiveMarker) {
                    pharmacyLiveMarker = window.L.circleMarker([latitude, longitude], {
                        radius: 8,
                        color: '#ffffff',
                        weight: 3,
                        fillColor: '#3178c6',
                        fillOpacity: 1
                    }).bindPopup('Your live location').addTo(pharmacyMap);
                    pharmacyDistanceLine = window.L.polyline([
                        [selectedPharmacyCoordinates.latitude, selectedPharmacyCoordinates.longitude],
                        [latitude, longitude]
                    ], {
                        color: '#3178c6',
                        weight: 2,
                        opacity: 0.75,
                        dashArray: '6 7'
                    }).addTo(pharmacyMap);
                    pharmacyMap.fitBounds([
                        [selectedPharmacyCoordinates.latitude, selectedPharmacyCoordinates.longitude],
                        [latitude, longitude]
                    ], { padding: [36, 36], maxZoom: 15 });
                } else {
                    pharmacyLiveMarker.setLatLng([latitude, longitude]);
                    pharmacyDistanceLine.setLatLngs([
                        [selectedPharmacyCoordinates.latitude, selectedPharmacyCoordinates.longitude],
                        [latitude, longitude]
                    ]);
                }
                const distance = distanceInKilometers(
                    latitude,
                    longitude,
                    selectedPharmacyCoordinates.latitude,
                    selectedPharmacyCoordinates.longitude
                );
                if (pharmacyLiveLocationStatus) {
                    pharmacyLiveLocationStatus.textContent = `Live location updated. ${distance.toFixed(2)} km from ${pharmacyLocationTitle.textContent}.`;
                }
            },
            (error) => {
                const messages = {
                    1: 'Location permission was denied. Allow it in your browser to use live location.',
                    2: 'Your current location is unavailable. Try again when GPS is available.',
                    3: 'The location request timed out. Try starting live location again.'
                };
                stopPharmacyLocationWatch(messages[error.code] ?? 'Could not read your live location.');
            },
            { enableHighAccuracy: true, maximumAge: 1000, timeout: 15000 }
        );
    };
    pharmacyLiveLocationToggle?.addEventListener('click', startPharmacyLocationWatch);
    pharmacyMapRecenter?.addEventListener('click', () => {
        if (pharmacyMap && pharmacyLiveMarker) {
            const currentLocation = pharmacyLiveMarker.getLatLng();
            pharmacyMap.setView([currentLocation.lat, currentLocation.lng], 16);
            pharmacyLiveMarker.openPopup();
        } else if (pharmacyMap && selectedPharmacyCoordinates) {
            pharmacyMap.setView([
                selectedPharmacyCoordinates.latitude,
                selectedPharmacyCoordinates.longitude
            ], 15);
            selectedPharmacyMarker?.openPopup();
        }
    });
    document.querySelectorAll('[data-view-location]').forEach((button) => {
        button.addEventListener('click', () => showPharmacyLocation(button));
    });
    document.querySelectorAll('[data-view-pharmacy]').forEach((button) => {
        button.addEventListener('click', () => showPharmacyDetails(button));
    });
    document.querySelectorAll('[data-close-pharmacy-details]').forEach((button) => {
        button.addEventListener('click', () => pharmacyDetailsDialog?.close());
    });
    pharmacyDetailsDialog?.addEventListener('click', (event) => {
        if (event.target === pharmacyDetailsDialog) pharmacyDetailsDialog.close();
    });
    pharmacyDetailsDialog?.addEventListener('close', () => {
        if (activePharmacyDetails && activePharmacyDetailsPlaceholder?.parentNode) {
            activePharmacyDetails.hidden = true;
            activePharmacyDetailsPlaceholder.replaceWith(activePharmacyDetails);
        }
        pharmacyDetailsInventory?.replaceChildren();
        activePharmacyDetails = null;
        activePharmacyDetailsPlaceholder = null;
        activePharmacyCard = null;
    });
    document.querySelector('[data-details-view-location]')?.addEventListener('click', () => {
        const locationButton = activePharmacyCard?.querySelector('[data-view-location]');
        pharmacyDetailsDialog?.close();
        window.setTimeout(() => locationButton?.click(), 0);
    });
    document.querySelectorAll('[data-close-pharmacy-location]').forEach((button) => {
        button.addEventListener('click', () => pharmacyLocationDialog?.close());
    });
    pharmacyLocationDialog?.addEventListener('click', (event) => {
        if (event.target === pharmacyLocationDialog) pharmacyLocationDialog.close();
    });
    pharmacyLocationDialog?.addEventListener('close', () => {
        stopPharmacyLocationWatch();
        selectedPharmacyCoordinates = null;
    });
    locationPreference?.addEventListener('change', () => {
        const enabled = locationPreference.checked;
        if (pharmacyLiveLocationToggle) {
            pharmacyLiveLocationToggle.disabled = !enabled || !selectedPharmacyCoordinates;
        }
        if (!enabled && pharmacyLocationWatchId !== null) {
            stopPharmacyLocationWatch('Live location stopped because Location Services was turned off in Settings.');
        }
    });
    if (pharmacyLiveLocationToggle) {
        pharmacyLiveLocationToggle.disabled = !locationPreferenceEnabled || !selectedPharmacyCoordinates;
    }
    pharmacyFilterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            setPharmacyFilter(button.dataset.pharmacyFilter ?? 'all');
            if (pharmacyFilter === 'nearest') {
                if (button.closest('.medicine-availability-filters')) {
                    requestMedicineSearchLocation();
                } else {
                    requestPharmacyLocation();
                }
            } else {
                if (pharmacyLocationStatus) pharmacyLocationStatus.hidden = true;
            }
            updatePharmacyResults();
        });
    });
    pharmacySearch?.addEventListener('input', () => {
        if (selectedMedicine) {
            clearSelectedMedicine();
        }
        updatePharmacyResults();
    });
    medicineSearchInput?.addEventListener('medifind:medicine-selected', (event) => {
        const medicine = event.detail;
        selectedMedicine = {
            id: medicine.id,
            name: String(medicine.name ?? 'Medicine')
        };
        if (pharmacySearch) {
            pharmacySearch.value = '';
        }
        updatePharmacyResults();
        Array.from(navigationItems ?? [])
            .find((item) => item.dataset.section === 'pharmacies')
            ?.click();
    });
    medicineSearchInput?.addEventListener('input', () => {
        if (selectedMedicine) {
            clearSelectedMedicine();
        }
    });
    reservationFilterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const filter = button.dataset.reservationFilter ?? 'all';
            let visibleCount = 0;
            reservationFilterButtons.forEach((filterButton) => {
                const isActive = filterButton === button;
                filterButton.classList.toggle('is-active', isActive);
                filterButton.setAttribute('aria-pressed', String(isActive));
            });
            reservationCards.forEach((card) => {
                const isVisible = filter === 'all' || card.dataset.reservationStatus === filter;
                card.hidden = !isVisible;
                visibleCount += Number(isVisible);
            });
            if (reservationResultsCount) {
                reservationResultsCount.textContent = `${visibleCount} ${visibleCount === 1 ? 'reservation' : 'reservations'}`;
            }
            if (reservationNoResults) {
                reservationNoResults.hidden = visibleCount > 0;
            }
        });
    });
    document.querySelectorAll('[data-pharmacy-medicine-search]').forEach((input) => {
        input.addEventListener('input', () => {
            const pharmacyDetails = input.closest('.pharmacy-card-details');
            if (!pharmacyDetails) {
                return;
            }
            const searchTerm = input.value.trim().toLocaleLowerCase();
            const medicineList = pharmacyDetails.querySelector('[data-pharmacy-medicine-results]');
            const medicineItems = pharmacyDetails.querySelectorAll('[data-pharmacy-medicine-item]');
            let visibleCount = 0;
            if (medicineList) {
                medicineList.hidden = searchTerm === '';
            }
            medicineItems.forEach((item) => {
                const matches = (item.dataset.medicineName ?? '')
                    .toLocaleLowerCase()
                    .includes(searchTerm);
                item.hidden = !matches;
                visibleCount += Number(matches);
            });
            const noResults = pharmacyDetails.querySelector('[data-pharmacy-medicine-no-results]');
            if (noResults) {
                noResults.hidden = searchTerm === '' || visibleCount > 0;
            }
        });
    });
    document.addEventListener('click', (event) => {
        const stepButton = event.target.closest('[data-quantity-step]');
        if (!stepButton) {
            return;
        }
        const quantityInput = stepButton.parentElement?.querySelector('input[type="number"]');
        if (!quantityInput) {
            return;
        }
        const step = Number(stepButton.dataset.quantityStep);
        const minimum = Number(quantityInput.min) || 1;
        const maximum = Number(quantityInput.max) || Number.MAX_SAFE_INTEGER;
        const current = Number(quantityInput.value) || minimum;
        quantityInput.value = String(Math.min(maximum, Math.max(minimum, current + step)));
    });
    themePreference?.addEventListener('change', () => {
        const selectedTheme = ['light', 'dark', 'system'].includes(themePreference.value)
            ? themePreference.value
            : 'system';
        saveSettingsPreference('theme', selectedTheme);
    });
    pharmacySortPreference?.addEventListener('change', () => {
        const selectedSort = ['nearest', 'available', 'alphabetical'].includes(pharmacySortPreference.value)
            ? pharmacySortPreference.value
            : 'nearest';
        saveSettingsPreference('pharmacySort', selectedSort);
        if (dashboard?.dataset.activeSection === 'pharmacies') {
            setPharmacyFilter('all');
        }
        updatePharmacyResults();
        if (
            selectedSort === 'nearest'
            && dashboard?.dataset.activeSection === 'pharmacies'
            && !pharmacyPosition
        ) {
            requestPharmacyLocation();
        }
    });
    languagePreference?.addEventListener('change', () => {
        const selectedLanguage = ['en', 'fil'].includes(languagePreference.value)
            ? languagePreference.value
            : 'en';
        saveSettingsPreference('language', selectedLanguage);
        document.documentElement.lang = selectedLanguage;
    });
    document.querySelectorAll('[data-setting]').forEach((control) => {
        if (!(control instanceof HTMLInputElement) || control.dataset.setting === 'theme') {
            return;
        }
        control.addEventListener('change', () => {
            saveSettingsPreference(control.dataset.setting, control.checked);
        });
    });
    useCurrentLocation?.addEventListener('click', () => {
        if (!locationPreferenceEnabled) {
            if (settingsLocationStatus) {
                settingsLocationStatus.textContent = 'Turn on Location Services before requesting your location.';
            }
            return;
        }
        if (settingsLocationStatus) {
            settingsLocationStatus.textContent = 'Requesting your location from the browser…';
        }
        useCurrentLocation.disabled = true;
        requestPharmacyLocation();
    });
    changePasswordButton?.addEventListener('click', () => {
        if (!changePasswordForm) {
            return;
        }
        changePasswordForm.hidden = !changePasswordForm.hidden;
        changePasswordButton.setAttribute('aria-expanded', String(!changePasswordForm.hidden));
        if (!changePasswordForm.hidden) {
            changePasswordForm.querySelector('input[name="current_password"]')?.focus();
        }
    });
    cancelPasswordChange?.addEventListener('click', () => {
        if (!changePasswordForm) {
            return;
        }
        changePasswordForm.reset();
        changePasswordForm.hidden = true;
        changePasswordButton?.setAttribute('aria-expanded', 'false');
    });
    const closeProfileEditor = () => {
        if (!profileEditModal) return;
        profileEditModal.hidden = true;
        if (profilePhotoActions) profilePhotoActions.hidden = true;
        profilePhotoActionsToggle?.setAttribute('aria-expanded', 'false');
        editProfileButton?.setAttribute('aria-expanded', 'false');
    };
    editProfileButton?.addEventListener('click', () => {
        if (!editProfileForm || !profileEditModal) return;
        profileEditModal.hidden = false;
        if (profilePhotoActions) profilePhotoActions.hidden = true;
        profilePhotoActionsToggle?.setAttribute('aria-expanded', 'false');
        editProfileButton.setAttribute('aria-expanded', 'true');
        editProfileForm.querySelector('input[name="full_name"]')?.focus();
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.profile-edit-photo')) {
            if (profilePhotoActions) profilePhotoActions.hidden = true;
            profilePhotoActionsToggle?.setAttribute('aria-expanded', 'false');
        }
    });
    profilePhotoActionsToggle?.addEventListener('click', () => {
        if (!profilePhotoActions) return;
        profilePhotoActions.hidden = !profilePhotoActions.hidden;
        profilePhotoActionsToggle.setAttribute('aria-expanded', String(!profilePhotoActions.hidden));
    });
    profilePhotoInput?.addEventListener('change', () => {
        const file = profilePhotoInput.files?.[0];
        if (!file || !profileEditPhotoImage) return;
        if (profilePhotoActions) profilePhotoActions.hidden = true;
        profilePhotoActionsToggle?.setAttribute('aria-expanded', 'false');
        let preview = profileEditPhotoImage.querySelector('img');
        if (!preview) {
            profileEditPhotoImage.textContent = '';
            preview = document.createElement('img');
            preview.alt = '';
            profileEditPhotoImage.append(preview);
        }
        preview.src = URL.createObjectURL(file);
    });
    closeProfileEdit?.addEventListener('click', closeProfileEditor);
    profileEditModal?.addEventListener('click', (event) => {
        if (event.target === profileEditModal) closeProfileEditor();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && profileEditModal && !profileEditModal.hidden) closeProfileEditor();
    });
    cancelProfileEdit?.addEventListener('click', () => {
        if (!editProfileForm) {
            return;
        }
        editProfileForm.reset();
        closeProfileEditor();
    });
    if (!dashboard || !sidebar || !indicator || !navigationItems?.length) {
        return;
    }
    const selectItem = (selectedItem) => {
        const section = selectedItem.dataset.section;
        if (!section) {
            return;
        }
        const enteringPharmacies = section === 'pharmacies'
            && dashboard.dataset.activeSection !== 'pharmacies';
        if (topbar) {
            topbar.hidden = section !== 'dashboard';
        }
        dashboard.dataset.activeSection = section;
        navigationItems.forEach((item) => {
            const isSelected = item === selectedItem;
            item.classList.toggle('is-expanded', isSelected);
            if (isSelected) {
                item.setAttribute('aria-current', 'page');
            } else {
                item.removeAttribute('aria-current');
            }
        });
        const selectedPanel = Array.from(featurePanels).find(
            (panel) => panel.dataset.featurePanel === section
        );
        featurePanels.forEach((panel) => {
            panel.hidden = panel.dataset.featurePanel === 'dashboard'
                ? Boolean(selectedPanel && selectedPanel !== panel)
                : panel !== selectedPanel;
        });
        if (section === 'pharmacies') {
            if (enteringPharmacies) {
                setPharmacyFilter('all');
            }
            updatePharmacyResults();
            if (settingsPreferences.pharmacySort === 'nearest' && !pharmacyPosition) {
                requestPharmacyLocation();
            }
        }
        if (sidebar.contains(selectedItem)) {
            const sidebarTop = sidebar.getBoundingClientRect().top + sidebar.clientTop;
            const itemTop = selectedItem.getBoundingClientRect().top;
            sidebar.style.setProperty('--active-nav-y', `${itemTop - sidebarTop}px`);
            sidebar.classList.add('has-active-item');
        }
    };
    navigationItems.forEach((item) => {
        item.addEventListener('click', (event) => {
            closeNotificationPreview();
            event.preventDefault();
            selectItem(item);
            window.history.replaceState(window.history.state, '', item.href);
        });
    });
    document.querySelector('[data-profile-security-link]')?.addEventListener('click', (event) => {
        event.preventDefault();
        Array.from(navigationItems)
            .find((item) => item.dataset.section === 'security')
            ?.click();
    });
    document.querySelector('.primary-button[href="#pharmacies"]')?.addEventListener('click', (event) => {
        event.preventDefault();
        Array.from(navigationItems)
            .find((item) => item.dataset.section === 'pharmacies')
            ?.click();
    });
    document.querySelectorAll('.activity-view-all[href="#pharmacies"]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            Array.from(navigationItems)
                .find((item) => item.dataset.section === 'pharmacies')
                ?.click();
        });
    });
    document.querySelector('.reservation-explore-button[href="#pharmacies"]')?.addEventListener('click', (event) => {
        event.preventDefault();
        Array.from(navigationItems)
            .find((item) => item.dataset.section === 'pharmacies')
            ?.click();
    });
    const initialSection = window.location.hash.slice(1);
    const initialItem = Array.from(navigationItems).find(
        (item) => item.dataset.section === initialSection
    ) ?? Array.from(navigationItems).find((item) => item.dataset.section === 'dashboard');
    if (initialItem) {
        selectItem(initialItem);
    }
    window.addEventListener('resize', () => {
        const selectedItem = sidebar.querySelector('.nav-link.is-expanded');
        if (selectedItem) {
            selectItem(selectedItem);
        }
    });
});



