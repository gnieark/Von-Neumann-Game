(function () {
    function setExpanded(button, expanded) {
        const section = button.closest(".stats-podium");
        if (!section) {
            return;
        }

        section.querySelectorAll("[data-stats-podium-extra]").forEach((row) => {
            row.hidden = !expanded;
        });
        button.setAttribute("aria-expanded", expanded ? "true" : "false");
        button.textContent = expanded
            ? (button.dataset.showLess || button.textContent)
            : (button.dataset.showMore || button.textContent);
    }

    document.querySelectorAll("[data-stats-podium-more]").forEach((button) => {
        button.addEventListener("click", () => {
            setExpanded(button, button.getAttribute("aria-expanded") !== "true");
        });
        setExpanded(button, false);
    });
}());
