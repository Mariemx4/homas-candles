// WHAT: prev/next controls for every `.carousel` on the page (used on
//       index.php's category/story/why-us carousels).
// WHY:  one shared script handles all carousels on a page — it loops over
//       every `.carousel` element instead of needing a separate script per
//       carousel instance.
const carousels = document.querySelectorAll(".carousel");

carousels.forEach((carousel) => {
  const slides = carousel.querySelectorAll(".carousel-slide");

  const previousButton = carousel.querySelector(
    ".carousel-button--previous"
    );

  const nextButton = carousel.querySelector(
    ".carousel-button--next"
  );

  const status = carousel.querySelector(".carousel-status");

  let currentIndex = 0;

  // WHAT: shows only the slide at currentIndex and updates the "N of M" status text.
  function updateCarousel() {
    slides.forEach((slide) => {
      slide.classList.remove("is-active");
    });
    slides[currentIndex].classList.add("is-active");
    status.textContent = `${currentIndex + 1} of ${slides.length}`;
  };

  nextButton.addEventListener("click", () => {
    currentIndex = currentIndex + 1;
    if (currentIndex >= slides.length) {
      currentIndex = 0;
    }
    updateCarousel();
  });

  previousButton.addEventListener("click", () => {
    currentIndex = currentIndex - 1;
    if (currentIndex < 0) {
      currentIndex = slides.length -1;
    }
    updateCarousel();
  });


});
