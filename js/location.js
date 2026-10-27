// Get the user's location, request nearby pharmacies, and show the results.
let nearbyMap;
let nearbyMapMarkers;

function createLocationMarkerIcon() {
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
}

function showPharmacyOnMap(map, pharmacy, index) {
    const latitude = Number(pharmacy.latitude ?? pharmacy.lat);
    const longitude = Number(pharmacy.longitude ?? pharmacy.lng ?? pharmacy.lon);
    if (
        !Number.isFinite(latitude)
        || latitude < -90
        || latitude > 90
        || !Number.isFinite(longitude)
        || longitude < -180
        || longitude > 180
    ) {
        return null;
    }

    const marker = window.L.marker([latitude, longitude], { icon: createLocationMarkerIcon() });
    const popup = document.createElement('div');
    const name = document.createElement('strong');
    name.textContent = String(pharmacy.name ?? pharmacy.pharmacy_name ?? `Pharmacy ${index + 1}`);
    popup.append(name);

    const address = document.createElement('p');
    address.textContent = `Address: ${String(
        pharmacy.address ?? pharmacy.pharmacy_address ?? pharmacy.location ?? 'Not provided'
    )}`;
    popup.append(address);

    const phone = document.createElement('p');
    phone.textContent = `Phone: ${String(
        pharmacy.phone ?? pharmacy.phone_number ?? pharmacy.contact_number ?? 'Not provided'
    )}`;
    popup.append(phone);

    const rawDistance = pharmacy.distance_km ?? pharmacy.distance ?? pharmacy.distance_in_km;
    const distance = rawDistance === null || rawDistance === undefined || rawDistance === ''
        ? Number.NaN
        : Number(rawDistance);
    const distanceLabel = document.createElement('p');
    distanceLabel.textContent = Number.isFinite(distance)
        ? `Distance: ${distance.toFixed(2)} km`
        : 'Distance: Not provided';
    popup.append(distanceLabel);

    marker.bindPopup(popup);
    marker.addTo(map);
    return [latitude, longitude];
}

function updateNearbyMap(userCoordinates, pharmacies) {
    const mapElement = document.querySelector('#map');
    if (!mapElement) {
        return [0, pharmacies.length];
    }
    if (!window.L) {
        throw new Error('The map could not load. Check your internet connection and refresh the page.');
    }

    if (!nearbyMap) {
        nearbyMap = window.L.map(mapElement, { scrollWheelZoom: false });
        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(nearbyMap);
        nearbyMapMarkers = window.L.layerGroup().addTo(nearbyMap);
    } else {
        nearbyMapMarkers.clearLayers();
    }

    const visibleCoordinates = [
        [userCoordinates.latitude, userCoordinates.longitude]
    ];
    const userMarker = window.L.marker(
        visibleCoordinates[0],
        { icon: createLocationMarkerIcon() }
    );
    const userPopup = document.createElement('strong');
    userPopup.textContent = 'Your current location';
    userMarker.bindPopup(userPopup);
    userMarker.addTo(nearbyMapMarkers);

    let mappedPharmacyCount = 0;
    pharmacies.forEach((pharmacy, index) => {
        const coordinates = showPharmacyOnMap(nearbyMapMarkers, pharmacy, index);
        if (coordinates) {
            visibleCoordinates.push(coordinates);
            mappedPharmacyCount += 1;
        }
    });

    if (visibleCoordinates.length === 1) {
        nearbyMap.setView(visibleCoordinates[0], 13);
    } else {
        nearbyMap.fitBounds(visibleCoordinates, { padding: [28, 28], maxZoom: 15 });
    }
    window.requestAnimationFrame(() => nearbyMap.invalidateSize());
    return [mappedPharmacyCount, pharmacies.length];
}

async function getMyLocation(onSuccess, onError, options = {}) {
    const statusElement = document.querySelector('#locationStatus');
    const resultsElement = document.querySelector('#nearbyPharmacies');
    const endpoint = options.endpoint ?? 'nearby_pharmacies.php';

    const showStatus = (message, state = '') => {
        if (statusElement) {
            statusElement.textContent = message;
            statusElement.dataset.state = state;
        }
    };

    const showError = (message, error = null) => {
        showStatus(message, 'error');
        if (typeof onError === 'function') {
            onError({ code: error?.code ?? 0, message, error });
        }
    };

    if (!navigator.geolocation) {
        showError('Geolocation is not supported by your browser.');
        return;
    }

    if (resultsElement) {
        resultsElement.replaceChildren();
    }
    showStatus('Getting your location…', 'loading');

    navigator.geolocation.getCurrentPosition(
        async (position) => {
            const latitude = position.coords.latitude;
            const longitude = position.coords.longitude;
            const coordinates = { latitude, longitude };

            console.log('Latitude:', latitude);
            console.log('Longitude:', longitude);

            if (typeof onSuccess === 'function') {
                onSuccess(coordinates, position);
            }

            showStatus('Searching for nearby pharmacies…', 'loading');

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams({
                        latitude: String(latitude),
                        longitude: String(longitude)
                    })
                });

                if (!response.ok) {
                    throw new Error(`The pharmacy request failed (HTTP ${response.status}).`);
                }

                const data = await response.json();
                if (data?.success === false || data?.error) {
                    throw new Error(
                        typeof data.error === 'string'
                            ? data.error
                            : (data.message ?? 'The pharmacy service could not find nearby pharmacies.')
                    );
                }

                const pharmacies = Array.isArray(data)
                    ? data
                    : (Array.isArray(data?.pharmacies)
                        ? data.pharmacies
                        : (Array.isArray(data?.data)
                            ? data.data
                            : (Array.isArray(data?.data?.pharmacies) ? data.data.pharmacies : null)));
                if (!pharmacies) {
                    throw new Error('The pharmacy service returned an unexpected response.');
                }

                const sortedPharmacies = pharmacies
                    .map((pharmacy) => {
                        const rawDistance = pharmacy.distance_km
                            ?? pharmacy.distance
                            ?? pharmacy.distance_in_km;
                        return {
                            pharmacy,
                            distance: rawDistance === null || rawDistance === undefined || rawDistance === ''
                                ? Number.NaN
                                : Number(rawDistance)
                        };
                    })
                    .sort((left, right) => {
                        const leftHasDistance = Number.isFinite(left.distance);
                        const rightHasDistance = Number.isFinite(right.distance);
                        if (leftHasDistance && rightHasDistance) {
                            return left.distance - right.distance;
                        }
                        return Number(rightHasDistance) - Number(leftHasDistance);
                    });

                let mappedPharmacyCount = 0;
                let mapError = '';
                try {
                    [mappedPharmacyCount] = updateNearbyMap(
                        coordinates,
                        sortedPharmacies.map(({ pharmacy }) => pharmacy)
                    );
                } catch (error) {
                    mapError = error instanceof Error
                        ? ` The map could not be displayed: ${error.message}`
                        : ' The map could not be displayed.';
                }

                if (resultsElement) {
                    sortedPharmacies.forEach(({ pharmacy, distance }) => {
                        const card = document.createElement('article');
                        card.className = 'nearby-pharmacy-result';

                        const name = document.createElement('h3');
                        name.textContent = String(pharmacy.name ?? pharmacy.pharmacy_name ?? 'Pharmacy');
                        card.append(name);

                        const address = document.createElement('p');
                        address.textContent = `Address: ${String(pharmacy.address ?? pharmacy.pharmacy_address ?? pharmacy.location ?? 'Not provided')}`;
                        card.append(address);

                        const phone = document.createElement('p');
                        phone.textContent = `Phone: ${String(pharmacy.phone ?? pharmacy.phone_number ?? pharmacy.contact_number ?? 'Not provided')}`;
                        card.append(phone);

                        const distanceLabel = document.createElement('p');
                        distanceLabel.textContent = Number.isFinite(distance)
                            ? `Distance: ${distance.toFixed(2)} km`
                            : 'Distance: Not provided';
                        card.append(distanceLabel);

                        resultsElement.append(card);
                    });
                }

                if (sortedPharmacies.length === 0) {
                    showStatus(
                        `No nearby pharmacies were found.${mapError}`,
                        mapError ? 'error' : 'empty'
                    );
                } else {
                    const missingCoordinates = sortedPharmacies.length - mappedPharmacyCount;
                    const mapNote = mapError
                        || (missingCoordinates > 0
                            ? ` ${missingCoordinates} ${missingCoordinates === 1 ? 'pharmacy has' : 'pharmacies have'} no valid map coordinates.`
                            : '');
                    showStatus(
                        `Found ${sortedPharmacies.length} nearby ${sortedPharmacies.length === 1 ? 'pharmacy' : 'pharmacies'}.${mapNote}`,
                        mapError ? 'error' : 'success'
                    );
                }
            } catch (error) {
                const message = error instanceof SyntaxError
                    ? 'The pharmacy service returned invalid JSON.'
                    : (error instanceof Error ? error.message : 'Could not load nearby pharmacies. Please try again.');
                showError(message, error);
            }
        },
        (error) => {
            let message;
            switch (error.code) {
                case error.PERMISSION_DENIED:
                    message = 'Location permission was denied. Allow location access in your browser and try again.';
                    break;
                case error.POSITION_UNAVAILABLE:
                    message = 'Your location is currently unavailable. Please try again.';
                    break;
                case error.TIMEOUT:
                    message = 'The location request timed out. Please try again.';
                    break;
                default:
                    message = 'Unable to get your location. Please try again.';
            }
            showError(message, error);
        },
        {
            enableHighAccuracy: options.enableHighAccuracy ?? true,
            timeout: options.timeout ?? 10000,
            maximumAge: options.maximumAge ?? 60000
        }
    );
}

window.getMyLocation = getMyLocation;
