document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('#reservationCsrfToken')?.value ?? '';
    let requestInProgress = false;

    const showMessage = (card, message, isError = false) => {
        let status = card.querySelector('.reservation-action-message');
        if (!status) {
            status = document.createElement('p');
            status.className = 'reservation-action-message';
            status.setAttribute('role', 'status');
            card.append(status);
        }
        status.classList.toggle('is-error', isError);
        status.classList.toggle('is-success', !isError);
        status.textContent = message;
    };

    const sendRequest = async (url, values) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: new URLSearchParams(values)
        });
        const data = await response.json();
        if (!response.ok || data.success !== true) {
            throw new Error(data.message ?? `Request failed (HTTP ${response.status}).`);
        }
        return data;
    };

    const reloadReservations = () => {
        window.location.hash = 'reservations';
        window.location.reload();
    };

    document.addEventListener('click', async (event) => {
        const reserveButton = event.target.closest('[data-reserve-medicine]');
        if (reserveButton) {
            if (requestInProgress) {
                return;
            }
            const card = reserveButton.closest(
                '.medicine-availability-card, [data-pharmacy-medicine-item]'
            );
            const quantityInput = card?.querySelector('input[type="number"]');
            const quantity = Number(quantityInput?.value);
            const maxQuantity = Number(quantityInput?.max);
            if (!quantityInput || !Number.isInteger(quantity) || quantity < 1 || quantity > maxQuantity) {
                showMessage(card, 'Enter a valid quantity that is currently available.', true);
                return;
            }

            requestInProgress = true;
            reserveButton.disabled = true;
            showMessage(card, 'Creating your reservation...');
            try {
                await sendRequest('reservations.php', {
                    action: 'create',
                    medicine_id: reserveButton.dataset.reserveMedicine,
                    pharmacy_id: reserveButton.dataset.reservePharmacy,
                    quantity: String(quantity),
                    csrf_token: csrfToken
                });
                reloadReservations();
            } catch (error) {
                showMessage(
                    card,
                    error instanceof Error ? error.message : 'The reservation could not be created.',
                    true
                );
                reserveButton.disabled = false;
            } finally {
                requestInProgress = false;
            }
            return;
        }

        const cancelButton = event.target.closest('[data-cancel-reservation]');
        if (!cancelButton || requestInProgress) {
            return;
        }
        if (!window.confirm('Cancel this reservation and release its stock hold?')) {
            return;
        }

        requestInProgress = true;
        cancelButton.disabled = true;
        try {
            await sendRequest('reservations.php', {
                action: 'cancel',
                reservation_id: cancelButton.dataset.cancelReservation,
                csrf_token: csrfToken
            });
            reloadReservations();
        } catch (error) {
            cancelButton.disabled = false;
            showMessage(
                cancelButton.closest('.reservation-card-details'),
                error instanceof Error ? error.message : 'The reservation could not be cancelled.',
                true
            );
        } finally {
            requestInProgress = false;
        }
    });
});
