const doctorForm = document.getElementById('doctorSignupForm');
GentleCareApi.populateTimeZoneSelect('doctorTimeZone');
if (doctorForm) {
  doctorForm.addEventListener('submit', function (event) {
    event.preventDefault();
    const status = document.getElementById('registrationStatus');
    const submitButton = doctorForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    GentleCareApi.showMessage(status, '', false);

    const email = document.getElementById('doctorEmail').value.trim();
    GentleCareApi.request('register', {
      role: 'doctor',
      name: document.getElementById('doctorName').value.trim(),
      email,
      password: document.getElementById('doctorPassword').value,
      profile: {
        licenseNumber: document.getElementById('doctorLicense').value.trim(),
        specialization: document.getElementById('doctorSpecialization').value.trim(),
        time_zone: document.getElementById('doctorTimeZone').value
      }
    }).then((result) => {
      GentleCareApi.showMessage(status, result.message, false);
      GentleCareApi.appendVerification(doctorForm, email, 'doctor', './doctor_dash.html', result.verificationCode);
    }).catch((error) => {
      GentleCareApi.showMessage(status, error.message, true);
      submitButton.disabled = false;
    });
  });
}

if (document.body.dataset.role === 'doctor') {
  const loginUrl = '../SignOrLog/signorlog.html';
  GentleCareApi.requireRole('doctor', loginUrl);

  const logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async function () {
      try {
        await GentleCareApi.request('logout', {});
        window.location.href = loginUrl;
      } catch (error) {
        alert(error.message);
      }
    });
  }
}
