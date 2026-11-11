import { Controller } from '@hotwired/stimulus';

/*
 * Check-in form on the rental desk: while the desk types the return odometer
 * and fuel level, the extra-charges field is suggested as late fee + excess
 * mileage + refuelling (the same rules as App\Service\Rental\ReturnChargeCalculator).
 * Once the desk edits the field by hand it is left alone.
 */
export default class extends Controller {
    static targets = ['odometer', 'fuel', 'total', 'breakdown'];
    static values = {
        checkoutOdometer: Number,
        checkoutFuel: Number,
        kmAllowance: Number,
        kmRate: Number,
        refuelRate: Number,
        lateFee: Number,
    };

    connect() {
        this.manual = false;
    }

    edited() {
        this.manual = true;
    }

    update() {
        const odometer = parseInt(this.odometerTarget.value, 10);
        const fuel = parseInt(this.fuelTarget.value, 10);
        const driven = Number.isNaN(odometer) ? 0 : Math.max(0, odometer - this.checkoutOdometerValue);
        const excessKm = this.kmAllowanceValue > 0 ? Math.max(0, driven - this.kmAllowanceValue) : 0;
        const mileage = excessKm * this.kmRateValue;
        const missingFuel = Number.isNaN(fuel) ? 0 : Math.max(0, this.checkoutFuelValue - fuel);
        const refuel = Math.ceil(missingFuel / 10) * this.refuelRateValue;

        const parts = [];
        if (this.lateFeeValue > 0) parts.push(`late ₱${this.lateFeeValue.toLocaleString()}`);
        if (mileage > 0) parts.push(`${excessKm.toLocaleString()} excess km ₱${mileage.toLocaleString()}`);
        if (refuel > 0) parts.push(`refuel ₱${refuel.toLocaleString()}`);
        this.breakdownTarget.textContent = parts.length ? `Suggested: ${parts.join(' + ')}.` : '';

        if (!this.manual) {
            this.totalTarget.value = this.lateFeeValue + mileage + refuel;
        }
    }
}
