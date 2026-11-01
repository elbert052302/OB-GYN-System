const loginForm = document.getElementById('loginForm');
const goToRegisterBtn = document.getElementById('goToRegisterBtn');
const createAccountType = document.getElementById('createAccountType');

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
      const destinations = {
        patient: '../Patient/patient_dash.html',
        doctor: '../Doctor/doctor_dash.html',
        clinic: '../Clinic/clinic_dash.html'
      };
      window.location.href = destinations[result.user.role];
    }).catch((error) => {
      GentleCareApi.showMessage(status, error.message, true);
      submitButton.disabled = false;
    });
  });
}

if (goToRegisterBtn) {
  goToRegisterBtn.addEventListener('click', function () {
    const type = createAccountType ? createAccountType.value : 'patient';

    if (type === 'patient') {
      window.location.href = '../Patient/patient.html';
    } else if (type === 'doctor') {
      window.location.href = '../Doctor/doctor.html';
    } else {
      window.location.href = '../Clinic/clinic.html';
    }
  });
}
