// WhatsApp widget
const waFab = document.getElementById('waFab');
const waPopup = document.getElementById('waPopup');
const waClose = document.getElementById('waClose');
const waOpenIcon = waFab.querySelector('.wa-fab-open');
const waCloseIcon = waFab.querySelector('.wa-fab-close');

function openWaPopup() {
  waPopup.classList.add('open');
  waOpenIcon.style.display = 'none';
  waCloseIcon.style.display = 'flex';
  waFab.querySelector('.wa-notification-dot').style.display = 'none';
}

function closeWaPopup() {
  waPopup.classList.remove('open');
  waOpenIcon.style.display = 'flex';
  waCloseIcon.style.display = 'none';
}

waFab.addEventListener('click', () => {
  waPopup.classList.contains('open') ? closeWaPopup() : openWaPopup();
});

waClose.addEventListener('click', (e) => {
  e.stopPropagation();
  closeWaPopup();
});

// Auto-open when the contact section scrolls into view (once per session)
if (!sessionStorage.getItem('waOpened')) {
  const contactSection = document.getElementById('contact');
  if (contactSection) {
    const waContactObserver = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting) {
        openWaPopup();
        sessionStorage.setItem('waOpened', '1');
        waContactObserver.disconnect();
      }
    }, { threshold: 0.2 });
    waContactObserver.observe(contactSection);
  }
}

// Nav scroll effect
const navWrapper = document.querySelector('.nav-wrapper');
window.addEventListener('scroll', () => {
  navWrapper.classList.toggle('scrolled', window.scrollY > 20);
});

// Mobile menu
const navToggle = document.querySelector('.nav-toggle');
const mobileMenu = document.getElementById('mobileMenu');
navToggle.addEventListener('click', () => {
  mobileMenu.classList.toggle('open');
});

function closeMobileMenu() {
  mobileMenu.classList.remove('open');
}

// Close mobile menu on outside click
document.addEventListener('click', (e) => {
  if (!navToggle.contains(e.target) && !mobileMenu.contains(e.target)) {
    mobileMenu.classList.remove('open');
  }
});

// Animate metric bars on scroll
const metricFills = document.querySelectorAll('.metric-fill');
const metricsCard = document.querySelector('.metrics-card');

if (metricsCard) {
  const widths = Array.from(metricFills).map(el => el.style.width);
  metricFills.forEach(el => el.style.width = '0');

  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting) {
      metricFills.forEach((el, i) => {
        setTimeout(() => { el.style.width = widths[i]; }, i * 120);
      });
      observer.disconnect();
    }
  }, { threshold: 0.3 });

  observer.observe(metricsCard);
}

// Contact form
function handleSubmit(e) {
  e.preventDefault();
  const form = document.getElementById('contactForm');
  form.innerHTML = `
    <div class="form-success">
      <div class="success-icon">✓</div>
      <h3>Message Received!</h3>
      <p>Thanks for reaching out. A member of our team will contact you within 1 business hour with your custom quote.</p>
    </div>
  `;
}

// Smooth scroll offset for fixed nav
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    const href = this.getAttribute('href');
    if (href === '#') return;
    const target = document.querySelector(href);
    if (!target) return;
    e.preventDefault();
    const top = target.getBoundingClientRect().top + window.scrollY - 80;
    window.scrollTo({ top, behavior: 'smooth' });
  });
});
