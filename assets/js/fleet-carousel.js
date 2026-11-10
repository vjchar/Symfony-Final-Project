/*
 * FILE: assets/js/fleet-carousel.js
 * PURPOSE: Fleet carousel/slider behavior.
 * USAGE: Controls vehicle carousel navigation and responsive interaction on pages that show horizontally browsable fleet content.
 * Maintenance: keep this script focused on the browser behavior described above.
 */

"use strict";

document.addEventListener("DOMContentLoaded", function () {
    const prefersReducedMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)",
    );

    document
        .querySelectorAll("[data-fleet-carousel]")
        .forEach(function (carousel) {
            const viewport = carousel.querySelector(
                ".fleet-carousel__viewport",
            );
            const previousButton = carousel.querySelector(
                "[data-carousel-previous]",
            );
            const nextButton = carousel.querySelector(
                "[data-carousel-next]",
            );
            const track = carousel.querySelector(".fleet-shelf__cars");

            if (!viewport || !previousButton || !nextButton || !track) {
                return;
            }

            let updateFrame = null;

            function maximumScrollLeft() {
                return Math.max(
                    0,
                    viewport.scrollWidth - viewport.clientWidth,
                );
            }

            function updateButtonStates() {
                const maximumScroll = maximumScrollLeft();
                const currentScroll = Math.max(0, viewport.scrollLeft);
                const hasOverflow = maximumScroll > 2;

                previousButton.disabled = !hasOverflow || currentScroll <= 2;
                nextButton.disabled =
                    !hasOverflow || currentScroll >= maximumScroll - 2;
            }

            function requestButtonStateUpdate() {
                if (updateFrame !== null) {
                    window.cancelAnimationFrame(updateFrame);
                }

                updateFrame = window.requestAnimationFrame(function () {
                    updateFrame = null;
                    updateButtonStates();
                });
            }

            function scrollDistance() {
                const firstVehicleCard = track.querySelector(".vehicle-card");

                if (!firstVehicleCard) {
                    return viewport.clientWidth;
                }

                const trackStyles = window.getComputedStyle(track);
                const columnGap =
                    Number.parseFloat(trackStyles.columnGap) || 0;

                return (
                    firstVehicleCard.getBoundingClientRect().width + columnGap
                );
            }

            function moveCarousel(direction) {
                viewport.scrollBy({
                    left: scrollDistance() * direction,
                    behavior: prefersReducedMotion.matches ? "auto" : "smooth",
                });
            }

            previousButton.addEventListener("click", function () {
                moveCarousel(-1);
            });

            nextButton.addEventListener("click", function () {
                moveCarousel(1);
            });

            viewport.addEventListener(
                "scroll",
                requestButtonStateUpdate,
                { passive: true },
            );

            viewport.addEventListener("keydown", function (event) {
                if (event.key === "ArrowLeft") {
                    event.preventDefault();
                    moveCarousel(-1);
                } else if (event.key === "ArrowRight") {
                    event.preventDefault();
                    moveCarousel(1);
                } else if (event.key === "Home") {
                    event.preventDefault();
                    viewport.scrollTo({
                        left: 0,
                        behavior: prefersReducedMotion.matches
                            ? "auto"
                            : "smooth",
                    });
                } else if (event.key === "End") {
                    event.preventDefault();
                    viewport.scrollTo({
                        left: maximumScrollLeft(),
                        behavior: prefersReducedMotion.matches
                            ? "auto"
                            : "smooth",
                    });
                }
            });

            if (typeof ResizeObserver === "function") {
                const carouselResizeObserver = new ResizeObserver(
                    requestButtonStateUpdate,
                );
                carouselResizeObserver.observe(viewport);
                carouselResizeObserver.observe(track);
            } else {
                window.addEventListener("resize", requestButtonStateUpdate);
            }

            track.querySelectorAll("img").forEach(function (vehicleImage) {
                if (!vehicleImage.complete) {
                    vehicleImage.addEventListener(
                        "load",
                        requestButtonStateUpdate,
                        { once: true },
                    );
                }
            });

            updateButtonStates();
        });
});
