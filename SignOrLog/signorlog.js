const loginForm = document.getElementById('loginForm');
const goToRegisterBtn = document.getElementById('goToRegisterBtn');
const createAccountType = document.getElementById('createAccountType');
const destinations = {
  patient: '../Patient/patient_dash.html',
  doctor: '../Doctor/doctor_dash.html',
  clinic: '../Clinic/clinic_dash.html'
};
const registrationDestinations = {
  patient: '../Patient/patient.html',
  doctor: '../Doctor/doctor.html',
  clinic: '../Clinic/clinic.html'
};
const requestedDashboard = new URLSearchParams(window.location.search).get('dashboard');
const dashboardLabels = {
  patient: 'Patient',
  doctor: 'Doctor',
  clinic: 'Clinic'
};
const requestedRole = Object.prototype.hasOwnProperty.call(destinations, requestedDashboard)
  ? requestedDashboard
  : null;

if (requestedRole) {
  document.getElementById('loginIntro').textContent =
    `Sign in with your ${dashboardLabels[requestedRole].toLowerCase()} account to open the ${dashboardLabels[requestedRole]} dashboard.`;
}

if (loginForm) {
  loginForm.addEventListener('submit', function (event) {
    event.preventDefault();

    const submitButton = loginForm.querySelector('button[type="submit"]');
    const status = document.getElementById('loginStatus');
    submitButton.disabled = true;
    GentleCareApi.showMessage(status, '', false);

    GentleCareApi.request('login', {
      identifier: document.getElementById('loginEmailPhone').value.trim(),
      password: document.getElementById('loginPassword').value
    }).then((result) => {
      if (requestedRole && result.user.role !== requestedRole) {
        GentleCareApi.showMessage(
          status,
          `This is the ${requestedRole} dashboard. Sign in with a ${requestedRole} account, or choose the ${result.user.role} dashboard for this account.`,
          true
        );
        submitButton.disabled = false;
        return;
      }
      window.location.href = destinations[result.user.role];
    }).catch((error) => {
      GentleCareApi.showMessage(status, error.message, true);
      submitButton.disabled = false;
    });
  });
}

if (goToRegisterBtn && createAccountType) {
  goToRegisterBtn.addEventListener('click', function () {
    const registrationPage = registrationDestinations[createAccountType.value];
    if (registrationPage) window.location.href = registrationPage;
  });
}
