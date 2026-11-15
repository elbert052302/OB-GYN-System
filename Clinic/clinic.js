const clinicSignupForm = document.getElementById('clinicSignupForm');
if (clinicSignupForm) {
	const clinicSubmitButton = clinicSignupForm.querySelector('button[type="submit"]');
	clinicSubmitButton.disabled = true;
	const clinicLatitude = document.getElementById('clinicLatitude');
	const clinicLongitude = document.getElementById('clinicLongitude');
	document.getElementById('clinicUseLocation').addEventListener('click', () => {
		const mapStatus = document.getElementById('clinicMapStatus');
		if (!navigator.geolocation) {
			mapStatus.textContent = 'Location is not supported by this browser. The clinic can be registered without a map pin.';
			return;
		}
		mapStatus.textContent = 'Getting this device’s location…';
		navigator.geolocation.getCurrentPosition((position) => {
			clinicLatitude.value = position.coords.latitude.toFixed(7);
			clinicLongitude.value = position.coords.longitude.toFixed(7);
			mapStatus.textContent = 'Map location added.';
		}, (error) => {
			mapStatus.textContent = error.code === error.PERMISSION_DENIED
				? 'Location permission was denied. The clinic can be registered without a map pin.'
				: 'Could not get this device’s location. Try again or register without a map pin.';
		}, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
	});
	GentleCareApi.populatePhilippineLocation('clinic').then(() => {
		clinicSubmitButton.disabled = false;
	}).catch((error) => {
		clinicSubmitButton.disabled = false;
		GentleCareApi.showMessage(document.getElementById('registrationStatus'), error.message, true);
	});

	clinicSignupForm.addEventListener('submit', function (event) {
		event.preventDefault();
		const status = document.getElementById('registrationStatus');
		clinicSubmitButton.disabled = true;
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
				country: 'PH',
				provinceCode: document.getElementById('clinicProvince').value,
				cityCode: document.getElementById('clinicCity').value,
				barangayCode: document.getElementById('clinicBarangay').value,
				street: document.getElementById('clinicStreet').value.trim(),
				latitude: clinicLatitude.value === '' ? null : Number(clinicLatitude.value),
				longitude: clinicLongitude.value === '' ? null : Number(clinicLongitude.value)
			}
		}).then((result) => {
			GentleCareApi.showMessage(status, result.message, false);
			GentleCareApi.appendVerification(clinicSignupForm, email, 'clinic', './clinic_dash.html', result.verificationCode);
		}).catch((error) => {
			GentleCareApi.showMessage(status, error.message, true);
			clinicSubmitButton.disabled = false;
		});
	});
}

if (document.body.dataset.role === 'clinic') {
	const loginUrl = '../SignOrLog/signorlog.html';
	const mapLocationForm = document.getElementById('clinicMapLocationForm');
	const mapLocationStatus = document.getElementById('clinicMapLocationStatus');
	const mapLatitude = document.getElementById('clinicMapLatitude');
	const mapLongitude = document.getElementById('clinicMapLongitude');
	const mapSaveButton = document.getElementById('clinicMapSaveButton');
	const viewLinks = Array.from(document.querySelectorAll('[data-view-link]'));
	const navLinks = Array.from(document.querySelectorAll('.nav [data-view-link]'));
	const viewPanels = Array.from(document.querySelectorAll('[data-view-panel]'));
	const viewNames = new Set(viewPanels.map((panel) => panel.dataset.viewPanel));
	const showClinicView = (viewName) => {
		if (!viewNames.has(viewName)) return;
		viewPanels.forEach((panel) => {
			panel.hidden = panel.dataset.viewPanel !== viewName;
		});
		navLinks.forEach((link) => {
			const active = link.dataset.viewLink === viewName;
			link.classList.toggle('active', active);
			if (active) link.setAttribute('aria-current', 'page');
			else link.removeAttribute('aria-current');
		});
		window.scrollTo(0, 0);
	};
	viewLinks.forEach((link) => {
		link.addEventListener('click', (event) => {
			event.preventDefault();
			if (window.location.hash !== link.hash) window.location.hash = link.hash;
			else showClinicView(link.dataset.viewLink);
		});
	});
	window.addEventListener('hashchange', () => {
		showClinicView(window.location.hash.slice(1) || 'dashboard');
	});
	showClinicView(window.location.hash.slice(1) || 'dashboard');

	GentleCareApi.requireRole('clinic', loginUrl).then((user) => {
		if (!user) return;
		const clinicName = user.clinicName || user.name;
		document.getElementById('clinicIdentity').textContent = clinicName;
		document.getElementById('welcomeText').textContent = `Welcome, ${clinicName}`;

		// Clinic-wide scheduling and reporting are planned features. Fill the empty
		// containers with an explicit notice so the dashboard never looks broken,
		// and disable the filters that would otherwise do nothing.
		const clinicNotice = (text) => {
			const note = document.createElement('p');
			note.className = 'demo-message';
			note.textContent = text;
			return note;
		};
		const appointmentList = document.getElementById('clinicAppointmentList');
		if (appointmentList && appointmentList.childElementCount === 0) {
			appointmentList.append(clinicNotice(
				'Clinic-wide appointment review is not connected yet. Booked appointments are visible to the doctor who owns the slot.'
			));
		}
		const operationalRecords = document.getElementById('clinicOperationalRecords');
		if (operationalRecords && operationalRecords.childElementCount === 0) {
			operationalRecords.append(clinicNotice(
				'Planned feature — operational records are not connected to clinic data yet.'
			));
		}
		const reportCards = document.getElementById('clinicReportCards');
		if (reportCards && reportCards.childElementCount === 0) {
			reportCards.append(clinicNotice(
				'Planned feature — clinic reports are not connected to clinic data yet.'
			));
		}
		const demoMessage = document.getElementById('clinicDemoMessage');
		if (demoMessage && demoMessage.textContent.trim() === '') {
			demoMessage.textContent = 'Planned feature — clinic-wide scheduling is not connected yet.';
		}
		['clinicAppointmentSearch', 'clinicStatusFilter'].forEach((id) => {
			const control = document.getElementById(id);
			if (control) {
				control.disabled = true;
				control.title = 'Not available yet';
			}
		});
		if (mapLocationForm) {
			GentleCareApi.requestApi('providers.php', 'mine').then((result) => {
				const location = result.data.location;
				mapLatitude.value = location.latitude ?? '';
				mapLongitude.value = location.longitude ?? '';
				mapLocationStatus.textContent = location.latitude === null
					? 'No map pin saved yet.'
					: 'Saved map pin loaded.';
			}).catch((error) => {
				mapLocationStatus.textContent = error.message;
			});

			document.getElementById('clinicMapUseLocation').addEventListener('click', () => {
				if (!navigator.geolocation) {
					mapLocationStatus.textContent = 'Location is not supported by this browser. The clinic can be saved without a map pin.';
					return;
				}
				mapLocationStatus.textContent = 'Getting this device’s location…';
				navigator.geolocation.getCurrentPosition((position) => {
					mapLatitude.value = position.coords.latitude.toFixed(7);
					mapLongitude.value = position.coords.longitude.toFixed(7);
					mapLocationStatus.textContent = 'Map location added. Save the pin to publish it.';
				}, (error) => {
					mapLocationStatus.textContent = error.code === error.PERMISSION_DENIED
						? 'Location permission was denied. The clinic can be saved without a map pin.'
						: 'Could not get this device’s location. Try again or save without a map pin.';
				}, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
			});

			mapLocationForm.addEventListener('submit', async (event) => {
				event.preventDefault();
				mapSaveButton.disabled = true;
				mapLocationStatus.textContent = 'Saving map pin…';
				try {
					await GentleCareApi.requestApi('providers.php', 'update_location', {
						latitude: mapLatitude.value === '' ? null : Number(mapLatitude.value),
						longitude: mapLongitude.value === '' ? null : Number(mapLongitude.value)
					});
					mapLocationStatus.textContent = 'Clinic map pin saved.';
				} catch (error) {
					mapLocationStatus.textContent = error.message;
				} finally {
					mapSaveButton.disabled = false;
				}
			});
		}
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
