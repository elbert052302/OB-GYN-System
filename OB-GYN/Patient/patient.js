const patientForm = document.getElementById('patientSignupForm');
if (patientForm) {
  const submitButton = patientForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  GentleCareApi.populatePhilippineLocation('patient').then(() => {
    submitButton.disabled = false;
  }).catch((error) => {
    GentleCareApi.showMessage(document.getElementById('registrationStatus'), error.message, true);
  });

  patientForm.addEventListener('submit', function (event) {
    event.preventDefault();
    const status = document.getElementById('registrationStatus');
    submitButton.disabled = true;
    GentleCareApi.showMessage(status, '', false);

    const email = document.getElementById('patientEmail').value.trim();
    GentleCareApi.request('register', {
      role: 'patient',
      name: document.getElementById('patientName').value.trim(),
      email,
      password: document.getElementById('patientPassword').value,
      profile: {
        dateOfBirth: document.getElementById('patientDob').value,
        emergencyContact: document.getElementById('patientEmergency').value.trim(),
        country: 'PH',
        provinceCode: document.getElementById('patientProvince').value,
        cityCode: document.getElementById('patientCity').value,
        barangayCode: document.getElementById('patientBarangay').value,
        street: document.getElementById('patientStreet').value.trim()
      }
    }).then((result) => {
      GentleCareApi.showMessage(status, result.message, false);
      GentleCareApi.appendVerification(patientForm, email, 'patient', './patient_dash.html', result.verificationCode);
    }).catch((error) => {
      GentleCareApi.showMessage(status, error.message, true);
      submitButton.disabled = false;
    });
  });
}
