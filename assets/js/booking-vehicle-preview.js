/*
 * FILE: assets/js/booking-vehicle-preview.js
 * PURPOSE: Booking-form vehicle preview behavior.
 * USAGE: Keeps the selected vehicle preview/details synchronized while a customer creates or edits a booking.
 * Maintenance: keep this script focused on the browser behavior described above.
 */

"use strict";

document.addEventListener("DOMContentLoaded", function () {
    const bookingForm = document.getElementById("bookingForm");
    const vehicleSelect = document.getElementById("vehicleChoice");
    const estimateButton = document.getElementById("bookingEstimateButton");
    const previewCard = document.querySelector(
        "[data-booking-vehicle-preview]",
    );

    if (!bookingForm || !vehicleSelect || !estimateButton || !previewCard) {
        return;
    }

    const estimateEndpoint = bookingForm.dataset.estimateUrl;
    const pickupDate = document.getElementById("pickupDate");
    const pickupTime = document.getElementById("pickupTime");
    const returnDate = document.getElementById("returnDate");
    const returnTime = document.getElementById("returnTime");
    const pickupLocation = document.getElementById("pickupLocation");
    const deliveryAddress = document.getElementById("deliveryAddress");
    const promotionCode = document.getElementById("promoCode");
    const pickupMethodOptions = bookingForm.querySelectorAll(
        'input[name="pickup_method"]',
    );
    const addOnOptions = bookingForm.querySelectorAll(
        'input[name="addons[]"]',
    );
    const childSeatOption = bookingForm.querySelector(
        'input[name="addons[]"][value="child-seat"]',
    );
    const childSeatQuantity = document.getElementById("childSeatQuantity");

    const previewElements = {
        image: document.getElementById("bookingVehiclePreviewImage"),
        nameLink: document.getElementById("bookingVehiclePreviewLink"),
        detailsLink: document.getElementById(
            "bookingVehiclePreviewDetailsLink",
        ),
        category: document.getElementById("bookingVehiclePreviewCategory"),
        rating: document.getElementById("bookingVehiclePreviewRating"),
        seats: document.getElementById("bookingVehiclePreviewSeats"),
        transmission: document.getElementById(
            "bookingVehiclePreviewTransmission",
        ),
        fuel: document.getElementById("bookingVehiclePreviewFuel"),
        price: document.getElementById("bookingVehiclePreviewPrice"),
        days: document.getElementById("bookingVehiclePreviewDays"),
        subtotal: document.getElementById("bookingVehiclePreviewSubtotal"),
        addOns: document.getElementById("bookingVehiclePreviewAddons"),
        delivery: document.getElementById("bookingVehiclePreviewDelivery"),
        promotionRow: document.getElementById(
            "bookingVehiclePreviewPromotionRow",
        ),
        discount: document.getElementById("bookingVehiclePreviewDiscount"),
        deposit: document.getElementById("bookingVehiclePreviewDeposit"),
        total: document.getElementById("bookingVehiclePreviewTotal"),
        message: document.getElementById("bookingVehicleMessage"),
    };

    let automaticEstimateTimer = null;
    let activeEstimateRequest = null;

    function updateText(element, value) {
        if (element) {
            element.textContent = value;
        }
    }

    function updateStatus(message, statusClass) {
        if (!previewElements.message) {
            return;
        }

        previewElements.message.classList.remove(
            "is-pending",
            "is-success",
            "is-error",
        );

        if (statusClass) {
            previewElements.message.classList.add(statusClass);
        }

        const messageText = previewElements.message.querySelector("span");
        updateText(messageText, message);
    }

    function selectedPickupMethod() {
        const checkedOption = bookingForm.querySelector(
            'input[name="pickup_method"]:checked',
        );

        return checkedOption ? checkedOption.value : "";
    }

    function validRentalSchedule() {
        if (!pickupDate || !pickupTime || !returnDate || !returnTime) {
            return false;
        }

        const pickupDateTime = new Date(
            pickupDate.value + "T" + pickupTime.value,
        );
        const returnDateTime = new Date(
            returnDate.value + "T" + returnTime.value,
        );

        return (
            Number.isFinite(pickupDateTime.getTime()) &&
            Number.isFinite(returnDateTime.getTime()) &&
            returnDateTime > pickupDateTime
        );
    }

    function bookingDetailsAreComplete() {
        if (
            !vehicleSelect.value ||
            !pickupLocation ||
            !pickupLocation.value ||
            !validRentalSchedule()
        ) {
            return false;
        }

        if (selectedPickupMethod() !== "Vehicle delivery") {
            return true;
        }

        return Boolean(
            deliveryAddress && deliveryAddress.value.trim().length >= 8,
        );
    }

    function updateVehiclePreview() {
        const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];

        if (!selectedOption) {
            return;
        }

        const vehicle = selectedOption.dataset;

        if (previewElements.image) {
            previewElements.image.src = vehicle.image;
            previewElements.image.alt = vehicle.name;
        }

        if (previewElements.nameLink) {
            previewElements.nameLink.href = vehicle.detailsUrl;
            previewElements.nameLink.textContent = vehicle.name;
        }

        if (previewElements.detailsLink) {
            previewElements.detailsLink.href = vehicle.detailsUrl;
        }

        updateText(previewElements.category, vehicle.category);
        updateText(previewElements.rating, vehicle.rating);
        updateText(previewElements.seats, vehicle.seats);
        updateText(previewElements.transmission, vehicle.transmission);
        updateText(previewElements.fuel, vehicle.fuel);
        updateText(previewElements.price, vehicle.price);
        updateText(previewElements.deposit, vehicle.deposit);

        previewCard.classList.add("estimate-is-stale");
        updateStatus(
            vehicle.name +
                " is selected. Complete the schedule to verify availability and pricing.",
            "",
        );
    }

    function setEstimateLoading(isLoading) {
        previewCard.classList.toggle("is-updating", isLoading);
        previewCard.setAttribute("aria-busy", String(isLoading));
        estimateButton.disabled = isLoading;
        estimateButton.classList.toggle("is-loading", isLoading);
        estimateButton.setAttribute("aria-busy", String(isLoading));
    }

    function applyEstimate(estimate) {
        updateText(previewElements.days, String(estimate.days));
        updateText(previewElements.subtotal, estimate.formatted.subtotal);
        updateText(previewElements.addOns, estimate.formatted.addons_total);
        updateText(previewElements.delivery, estimate.formatted.delivery_fee);
        updateText(previewElements.deposit, estimate.formatted.deposit);
        updateText(previewElements.total, estimate.formatted.total);
        updateText(previewElements.discount, estimate.formatted.discount);

        if (previewElements.promotionRow) {
            previewElements.promotionRow.hidden = estimate.discount <= 0;
        }

        previewCard.classList.remove("estimate-is-stale");
    }

    async function requestEstimate() {
        window.clearTimeout(automaticEstimateTimer);

        if (!bookingDetailsAreComplete()) {
            updateStatus(
                "Choose a valid vehicle, branch, pickup, and return schedule to calculate the estimate.",
                "is-error",
            );
            return;
        }

        if (!estimateEndpoint || typeof window.fetch !== "function") {
            bookingForm.requestSubmit(estimateButton);
            return;
        }

        if (activeEstimateRequest) {
            activeEstimateRequest.abort();
        }

        const estimateRequest = new AbortController();
        activeEstimateRequest = estimateRequest;
        const requestBody = new FormData(bookingForm);
        requestBody.set("form_action", "preview");

        setEstimateLoading(true);
        updateStatus(
            "Checking the schedule and calculating the latest price…",
            "is-pending",
        );

        try {
            const response = await window.fetch(estimateEndpoint, {
                method: "POST",
                body: requestBody,
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                },
                signal: estimateRequest.signal,
            });

            const responseData = await response.json();

            if (!response.ok || !responseData.ok) {
                throw new Error(
                    responseData.message ||
                        "The estimate could not be calculated.",
                );
            }

            applyEstimate(responseData.estimate);
            updateStatus(responseData.message, "is-success");
        } catch (requestError) {
            if (requestError.name === "AbortError") {
                return;
            }

            previewCard.classList.add("estimate-is-stale");
            updateStatus(
                requestError.message ||
                    "The estimate could not be calculated. Try again.",
                "is-error",
            );
        } finally {
            if (activeEstimateRequest === estimateRequest) {
                activeEstimateRequest = null;
                setEstimateLoading(false);
            }
        }
    }

    function scheduleAutomaticEstimate() {
        window.clearTimeout(automaticEstimateTimer);
        previewCard.classList.add("estimate-is-stale");

        if (!bookingDetailsAreComplete()) {
            return;
        }

        automaticEstimateTimer = window.setTimeout(requestEstimate, 450);
    }

    vehicleSelect.addEventListener("change", function () {
        updateVehiclePreview();
        scheduleAutomaticEstimate();
    });

    [pickupDate, pickupTime, returnDate, returnTime, pickupLocation].forEach(
        function (inputElement) {
            if (inputElement) {
                inputElement.addEventListener(
                    "change",
                    scheduleAutomaticEstimate,
                );
            }
        },
    );

    pickupMethodOptions.forEach(function (inputElement) {
        inputElement.addEventListener("change", scheduleAutomaticEstimate);
    });

    addOnOptions.forEach(function (inputElement) {
        inputElement.addEventListener("change", function () {
            if (inputElement === childSeatOption && childSeatQuantity) {
                childSeatQuantity.disabled = !childSeatOption.checked;
            }
            scheduleAutomaticEstimate();
        });
    });

    if (childSeatQuantity) {
        childSeatQuantity.addEventListener("click", function (event) {
            event.stopPropagation();
        });
        childSeatQuantity.addEventListener(
            "change",
            scheduleAutomaticEstimate,
        );
    }

    if (deliveryAddress) {
        deliveryAddress.addEventListener("change", scheduleAutomaticEstimate);
    }

    if (promotionCode) {
        promotionCode.addEventListener("change", scheduleAutomaticEstimate);
    }

    bookingForm.addEventListener("submit", function (event) {
        window.clearTimeout(automaticEstimateTimer);

        if (event.submitter === estimateButton) {
            event.preventDefault();
            requestEstimate();
        }
    });
});
