const clinicSignupForm = document.getElementById('clinicSignupForm');
GentleCareApi.populateTimeZoneSelect('clinicTimeZone');

if (clinicSignupForm) {
	clinicSignupForm.addEventListener('submit', function (event) {
		event.preventDefault();
		const status = document.getElementById('registrationStatus');
		const submitButton = clinicSignupForm.querySelector('button[type="submit"]');
		submitButton.disabled = true;
		GentleCareApi.showMessage(status, '', false);

		const email = document.getElementById('clinicEmail').value.trim();
		GentleCareApi.request('register', {
			role: 'clinic',
			name: document.getElementById('clinicName').value.trim(),
			email,
			phone: document.getElementById('clinicPhone').value.trim(),
			password: document.getElementById('clinicPassword').value,
			profile: {
				clinicName: document.getElementById('clinicName').value.trim(),
				address: document.getElementById('clinicAddress').value.trim(),
				time_zone: document.getElementById('clinicTimeZone').value
			}
		}).then((result) => {
			GentleCareApi.showMessage(status, result.message, false);
			GentleCareApi.appendVerification(clinicSignupForm, email, 'clinic', './clinic_dash.html', result.verificationCode);
		}).catch((error) => {
			GentleCareApi.showMessage(status, error.message, true);
			submitButton.disabled = false;
		});
	});
}

if (document.body.dataset.role === 'clinic') {
	const loginUrl = '../SignOrLog/signorlog.html';
	GentleCareApi.requireRole('clinic', loginUrl).then((user) => {
		if (!user) return;
		const clinicName = user.clinicName || user.name;
		document.getElementById('clinicIdentity').textContent = clinicName;
		document.getElementById('welcomeText').textContent = `Welcome, ${clinicName}`;
	});

	document.getElementById('logoutBtn').addEventListener('click', async function () {
		try {
			await GentleCareApi.request('logout', {});
			window.location.href = loginUrl;
		} catch (error) {
			alert(error.message);
		}
	});
}
