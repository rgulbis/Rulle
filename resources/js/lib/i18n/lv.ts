import type { TranslationKey } from './en';

const lv: Record<TranslationKey, string> = {
    // Nav (app-layout.tsx)
    'nav.brand': 'Rullē',
    'nav.dashboard': 'Panelis',
    'nav.subscriptions': 'Abonementi',
    'nav.reservations': 'Rezervācijas',
    'nav.scan': 'Skenēt',
    'nav.chat': 'Čats',
    'nav.livestream': 'Tiešraide',
    'nav.admin': 'Administrācija',
    'nav.changePassword': 'Mainīt paroli',
    'nav.logOut': 'Izrakstīties',
    'nav.logIn': 'Pieslēgties',

    // Livestream (pages/livestream/index.tsx)
    'livestream.title': 'Tiešraide',
    'livestream.subtitle':
        'Skats uz parku tiešraidē. Netiek ierakstīts vai saglabāts.',
    'livestream.checkedInCount_one': 'Parkā ir {count} cilvēks',
    'livestream.checkedInCount_other': 'Parkā ir {count} cilvēki',
    'livestream.noReservationsToday': 'Šodien nav rezervāciju.',
    'livestream.reservedToday': 'Šodien rezervēts: {ranges}',
    'livestream.offline':
        'Kameras signāls pašlaik nav pieejams — mēģiniet vēlāk.',
    'livestream.unsupportedBrowser':
        'Jūsu pārlūkprogramma nevar atskaņot šo straumi.',

    // Dashboard (pages/dashboard.tsx)
    'dashboard.welcome': 'Laipni lūdzam, {name}!',
    'dashboard.loggedIn': 'Pieslēgšanās veiksmīga.',
    'dashboard.verificationSent':
        'Jauna apstiprināšanas saite ir nosūtīta uz jūsu e-pasta adresi.',
    'dashboard.pleaseVerify':
        'Lūdzu, apstipriniet savu e-pasta adresi, lai abonētu vai veiktu pirkumus.',
    'dashboard.resendVerification': 'Nosūtīt apstiprināšanas e-pastu vēlreiz',
    'dashboard.checkedIn': 'Parkā',
    'dashboard.notCheckedIn': 'Nav parkā',
    'dashboard.verifyForQrCode':
        'Apstipriniet savu e-pastu, lai saņemtu ieejas QR kodu.',

    // Reservations (pages/reservations/index.tsx)
    'reservations.title': 'Rezervēt parku',
    'reservations.statusComplete':
        'Rezervācija apstiprināta — parks ir jūsu rīcībā šajā laikā.',
    'reservations.statusIncomplete':
        'Maksājums netika pabeigts, tāpēc nekas netika rezervēts.',
    'reservations.statusCancelled': 'Rezervācija atcelta.',
    'reservations.statusCancelledRefunded':
        'Rezervācija atcelta un nauda atmaksāta.',
    'reservations.statusCancelledNoRefund':
        'Rezervācija atcelta, bet tas bija pārāk tuvu sākuma laikam, lai atmaksātu naudu.',
    'reservations.statusCannotCancel':
        'Šī rezervācija jau ir sākusies, un to vairs nevar atcelt.',
    'reservations.statusSlotTaken':
        'Kāds cits šo laiku rezervēja pirmais, kamēr jūs maksājāt — nauda jums atmaksāta, un rezervācija atcelta. Izvēlieties citu laiku.',
    'reservations.reserveATime': 'Rezervēt laiku',
    'reservations.date': 'Datums',
    'reservations.time': 'Laiks',
    'reservations.timelineHint':
        '{min}–{max} minūtes, ar 15 minūšu soli. Punktētā līnija rāda parasti noslogotās stundas; sarkanais bloks jau ir rezervēts.',
    'reservations.groupSize': 'Grupas lielums (cilvēki)',
    'reservations.groupSizeHint':
        '{min}–{max} cilvēki — cena tiek aprēķināta par personu, par stundu.',
    'reservations.price': 'Cena: {amount}',
    'reservations.reserveAndPay': 'Rezervēt un maksāt',
    'reservations.upcoming': 'Gaidāmās rezervācijas',
    'reservations.upcomingHint':
        'Šajos laikos parks ir privāti rezervēts — pārējo ieeja ir apturēta, līdz tie beidzas. Noklikšķiniet uz dienas, lai redzētu tās rezervētos laikus, vai lai rezervētu šo dienu augstāk.',
    'reservations.mine': 'Manas rezervācijas',
    'reservations.noneUpcoming': 'Jums nav gaidāmu rezervāciju.',
    'reservations.peopleCount': '{count} cilvēki',
    'reservations.namedOf': '{named} no {total} pievienoti',
    'reservations.groupChat': 'Grupas tērzētava',
    'reservations.finishPayment': 'Pabeigt maksājumu',
    'reservations.cancel': 'Atcelt rezervāciju',
    'reservations.confirmCancel': 'Atcelt šo rezervāciju?',
    'reservations.remove': 'Noņemt',
    'reservations.groupFull':
        'Šīs rezervācijas grupa ir pilna — noņemiet kādu vai izveidojiet jaunu rezervāciju vairāk cilvēkiem.',
    'reservations.searchPlaceholder':
        'Meklēt pēc vārda vai e-pasta, lai pievienotu draugu',
    'reservations.searching': 'Meklē…',
    'reservations.add': 'Pievienot',
    'reservations.selectDayHint':
        'Izvēlieties dienu, lai redzētu rezervētos laikus.',
    'reservations.noneThatDay': 'Šajā dienā nav rezervāciju.',
    'reservations.previousMonth': 'Iepriekšējais mēnesis',
    'reservations.nextMonth': 'Nākamais mēnesis',
    'reservations.chooseStart': 'Noklikšķiniet, lai izvēlētos sākuma laiku',
    'reservations.chooseEnd': '{start} – noklikšķiniet uz beigu laika',
    'reservations.selectionSummary': '{start} – {end} ({minutes} min)',
    'reservations.reset': 'Atiestatīt',
    'reservations.status.pending': 'gaida apmaksu',
    'reservations.status.active': 'aktīva',
    'reservations.status.cancelled': 'atcelta',

    // Reservation group chat (pages/reservations/chat.tsx)
    'reservationChat.back': '← Atpakaļ uz rezervācijām',
    'reservationChat.title': 'Grupas tērzētava — {range}',
    'reservationChat.subtitle':
        'Redzama tikai jūsu grupai — īpašniekam un draugiem, kurus viņš vai viņa pievienojis.',

    // Subscriptions (pages/subscriptions/index.tsx)
    'subscriptions.title': 'Abonementi',
    'subscriptions.statusPurchaseComplete':
        'Pirkums pabeigts — jūsu piekļuve tagad ir aktīva.',
    'subscriptions.statusPurchaseIncomplete':
        'Maksājums netika pabeigts, tāpēc nekas netika aktivizēts.',
    'subscriptions.statusPurchaseCancelled': 'Maksājums tika atcelts.',
    'subscriptions.statusSubscriptionCancelled': 'Jūsu abonements ir atcelts.',
    'subscriptions.statusPriceUpdated':
        'Jūsu abonements tagad izmanto jauno cenu.',
    'subscriptions.cancelledUntil': 'Atcelts — piekļuve beidzas {date}.',
    'subscriptions.periodEnd': 'perioda beigās',
    'subscriptions.active': 'Aktīvs abonements ({status})',
    'subscriptions.cancelSubscription': 'Atcelt abonementu',
    'subscriptions.confirmCancel':
        'Atcelt abonementu? Piekļuve saglabāsies līdz pašreizējā norēķinu perioda beigām.',
    'subscriptions.priceChanged':
        'Šī plāna cena ir mainījusies: jums ir {current}, pašreizējā cena ir {new}.',
    'subscriptions.switchToNewPrice': 'Pāriet uz jauno cenu',
    'subscriptions.unlimitedToday': 'Neierobežota ieeja šodien',
    'subscriptions.visitsRemaining_one': 'Atlicis {count} apmeklējums',
    'subscriptions.visitsRemaining_other': 'Atlikuši {count} apmeklējumi',
    'subscriptions.unlimitedSameDay': 'Neierobežota ieeja tajā pašā dienā',
    'subscriptions.visitsCount_one': '{count} apmeklējums',
    'subscriptions.visitsCount_other': '{count} apmeklējumi',
    'subscriptions.alreadySubscribed': 'Jau abonēts',
    'subscriptions.buy': 'Pirkt',
    'subscriptions.subscribe': 'Abonēt',
    'subscriptions.perMonth': '{amount} / mēnesī',
    'subscriptions.perYear': '{amount} / gadā',
    'subscriptions.none': 'Pašlaik nav pieejamu plānu.',

    // Chat (pages/chat/index.tsx)
    'chat.title': 'Čats',

    // Chat thread component (components/chat-thread.tsx)
    'chatThread.pinned': 'Piespraustās',
    'chatThread.unpin': 'Atspraust',
    'chatThread.pin': 'Piespraust',
    'chatThread.moreActions': 'Vairāk darbību',
    'chatThread.noMessages': 'Vēl nav ziņu — uzrakstiet kaut ko.',
    'chatThread.mute': 'Apklusināt {duration}',
    'chatThread.muteHour': '1 stundu',
    'chatThread.muteDay': '1 dienu',
    'chatThread.muteWeek': '1 nedēļu',
    'chatThread.muteSuccess': 'Lietotājs {name} apklusināts uz {duration}.',
    'chatThread.muteFailed': 'Neizdevās apklusināt šo personu.',
    'chatThread.delete': 'Dzēst',
    'chatThread.slowModeStaff':
        'Ir ieslēgts lēnais režīms — personālam tas neattiecas.',
    'chatThread.slowModeCustomer':
        'Ir ieslēgts lēnais režīms — viena ziņa ik pēc {seconds}s.',
    'chatThread.mutedUntil': 'Jūs esat apklusināts čatā līdz {date}.',
    'chatThread.muted': 'Jūs esat apklusināts čatā.',
    'chatThread.placeholder': 'Uzrakstiet kaut ko…',
    'chatThread.slowModeWait':
        'Ieslēgts lēnais režīms — pagaidiet {seconds} s, pirms sūtāt nākamo ziņu.',
    'chatThread.wait': 'Gaidiet {seconds}s',
    'chatThread.send': 'Sūtīt',
    'chatThread.sendFailed': 'Neizdevās nosūtīt šo ziņu.',

    // Staff scan (pages/staff/scan.tsx)
    'scan.title': 'Skenēt biedra QR kodu',
    'scan.entry': 'Ieeja',
    'scan.exit': 'Izeja',
    'scan.checkedIn': 'Parkā',
    'scan.checkedOut': 'Ārā',
    'scan.unreachable': 'Neizdevās sazināties ar serveri.',

    // Settings / password (pages/settings/password.tsx)
    'settings.password.title': 'Mainīt paroli',
    'settings.password.subtitle':
        'Pārliecinieties, ka jūsu kontam ir gara, nejauši ģenerēta parole, lai saglabātu drošību.',
    'settings.password.updated': 'Jūsu parole ir atjaunināta.',
    'settings.password.current': 'Pašreizējā parole',
    'settings.password.new': 'Jaunā parole',
    'settings.password.confirm': 'Apstipriniet jauno paroli',
    'settings.password.submit': 'Atjaunināt paroli',

    // Auth — shared
    'auth.email': 'E-pasts',
    'auth.password': 'Parole',

    // Auth — login
    'auth.login.title': 'Pieslēgties',
    'auth.login.description':
        'Ievadiet savu e-pastu un paroli, lai pieslēgtos.',
    'auth.login.forgotPassword': 'Aizmirsāt paroli?',
    'auth.login.rememberMe': 'Atcerēties mani',
    'auth.login.submit': 'Pieslēgties',
    'auth.login.noAccount': 'Nav konta?',
    'auth.login.signUp': 'Reģistrēties',

    // Auth — register
    'auth.register.title': 'Izveidot kontu',
    'auth.register.description': 'Ievadiet savus datus zemāk, lai reģistrētos.',
    'auth.register.name': 'Vārds',
    'auth.register.confirmPassword': 'Apstipriniet paroli',
    'auth.register.submit': 'Reģistrēties',
    'auth.register.haveAccount': 'Jau ir konts?',
    'auth.register.logIn': 'Pieslēgties',

    // Auth — forgot password
    'auth.forgotPassword.title': 'Aizmirsu paroli',
    'auth.forgotPassword.description':
        'Ievadiet savu e-pastu, un mēs nosūtīsim saiti paroles atiestatīšanai.',
    'auth.forgotPassword.submit': 'Nosūtīt paroles atiestatīšanas saiti',
    'auth.forgotPassword.remembered': 'Atcerējāties savu paroli?',
    'auth.forgotPassword.logIn': 'Pieslēgties',

    // Auth — reset password
    'auth.resetPassword.title': 'Atiestatīt paroli',
    'auth.resetPassword.description': 'Ievadiet savu jauno paroli zemāk.',
    'auth.resetPassword.confirmPassword': 'Apstipriniet jauno paroli',
    'auth.resetPassword.submit': 'Atiestatīt paroli',

    // Auth — verify email
    'auth.verifyEmail.title': 'Apstiprināt e-pastu',
    'auth.verifyEmail.description':
        'Paldies, ka reģistrējāties! Pirms sākat, lūdzu, apstipriniet savu e-pasta adresi, noklikšķinot uz saites, ko tikko nosūtījām.',
    'auth.verifyEmail.sent':
        'Jauna apstiprināšanas saite ir nosūtīta uz e-pasta adresi, ko norādījāt reģistrējoties.',
    'auth.verifyEmail.resend': 'Nosūtīt apstiprināšanas e-pastu vēlreiz',
    'auth.verifyEmail.logOut': 'Izrakstīties',

    // Nav, footer & park status (layouts/app-layout.tsx)
    'nav.signUp': 'Reģistrēties',
    'nav.staffTag': 'Personāls',
    'nav.main': 'Galvenā navigācija',
    'nav.openMenu': 'Atvērt izvēlni',
    'nav.closeMenu': 'Aizvērt izvēlni',
    'nav.language': 'Valoda',
    'park.openUntil': 'Atvērts · līdz {time}',
    'park.closedOpensAt': 'Slēgts · atveras {time}',
    'park.stickerOpen': 'Atvērts līdz {time}',
    'park.stickerClosed': 'Šobrīd slēgts',
    'footer.hours': 'Darba laiks',
    'footer.everyDay': 'Katru dienu {opening}–{closing}',
    'footer.park': 'Parks',

    // Shared form bits (components/form-controls.tsx, password-checklist.tsx)
    'form.showPassword': 'Rādīt paroli',
    'form.hidePassword': 'Slēpt paroli',
    'form.emailPlaceholder': 'vards@piemers.lv',
    'form.passwordsMatch': 'Paroles sakrīt',
    'form.passwordsDontMatch': 'Paroles vēl nesakrīt',
    'form.rule.length': 'Vismaz 10 rakstzīmes',
    'form.rule.mixedCase': 'Lielie un mazie burti',
    'form.rule.number': 'Cipars',
    'form.rule.symbol': 'Simbols',
    'form.rule.met': '(izpildīts)',
    'form.rule.notMet': '(vēl nav)',

    // Live headcount (components/live.tsx)
    'occupancy.quiet': 'mierīgi',
    'occupancy.gettingBusy': 'kļūst rosīgi',
    'occupancy.busy': 'daudz cilvēku',
    'occupancy.meterLabel': 'Noslodze: {level} no 10',
    'livestream.live': 'TIEŠRAIDE',
    'livestream.inParkNow': 'Parkā šobrīd',
    'livestream.today': 'Šodien',
    'livestream.openHours': 'Atvērts {opening}–{closing}',
    'livestream.cta': 'Izskatās labi? Nāciet braukt.',

    // Home (pages/welcome.tsx)
    'home.title': 'Skeitparks',
    'home.heroLead': 'Brauciet vairāk.',
    'home.heroQueue': 'Gaidiet',
    'home.heroHighlight': 'mazāk.',
    'home.intro':
        'Rampas, bowls un rails visiem līmeņiem — no pirmā lēciena līdz sarežģītākajiem trikiem. Iekštelpu parks, atvērts katru dienu neatkarīgi no laika apstākļiem.',
    'home.seePasses': 'Skatīt abonementus',
    'home.watchLive': 'Skatīties tiešraidi',
    'home.parkRightNow': 'Parks šobrīd',
    'home.skatingNow': 'šobrīd parkā',
    'home.camera': 'Kamera',
    'home.countedFromCheckIns': 'Skaitīts pēc ienākšanas pie ieejas',
    'home.ticker.hours': 'Šodien atvērts {opening}–{closing}',
    'home.ticker.skating': 'Parkā šobrīd: {count}',
    'home.ticker.reserved': 'Šodien rezervēts: {ranges}',
    'home.ticker.noReservations': 'Šodien nav privātu rezervāciju',
    'home.ticker.howItWorks': 'Pērciet tiešsaistē · QR kods pie ieejas',
    'home.step1.title': 'Izvēlieties abonementu',
    'home.step1.text':
        'Vienreizēja ieeja, mēneša vai gada abonements. Maksājiet tiešsaistē ar karti.',
    'home.step2.title': 'QR kods pie ieejas',
    'home.step2.text':
        'Jūsu QR kods ir jūsu kontā. Darbinieks to noskenē, un varat iet iekšā.',
    'home.step3.title': 'Brauciet',
    'home.step3.text': 'Jautājumi? Rakstiet darbiniekiem čatā jebkurā laikā.',
    'home.passesTitle': 'Abonementi',
    'home.passesIntro':
        'Katram abonementam ir personīgs QR kods. Mēneša un gada abonementi atjaunojas automātiski — atceliet jebkurā brīdī.',
    'home.crewLead': 'Atvediet',
    'home.crewTail': 'savējos.',
    'home.crewText':
        'Rezervējiet visu parku savai grupai — dzimšanas dienām, skolas klasēm, komandām. {price} par cilvēku stundā, no {min} cilvēkiem.',
    'home.bookSlot': 'Rezervēt laiku',
    'home.todayTitle': 'Šodien parkā',
    'home.todayFree': 'Šodien nav privātu rezervāciju — parks atvērts visiem.',
    'home.privatelyBooked': 'Rezervēts',

    // Dashboard cards (pages/dashboard.tsx)
    'dashboard.entryPass': 'Ieejas karte',
    'dashboard.showAtDesk': 'Parādiet šo pie ieejas',
    'dashboard.brightnessHint':
        'Palieliniet ekrāna spilgtumu, lai kods nolasās ar pirmo reizi.',
    'dashboard.subscription': 'Abonements',
    'dashboard.accessEnds': 'Atcelts — piekļuve beidzas {date}',
    'dashboard.renewsAutomatically': 'Atjaunojas automātiski',
    'dashboard.yourPass': 'Jūsu abonements',
    'dashboard.manage': 'Pārvaldīt',
    'dashboard.noPass': 'Jums vēl nav aktīva abonementa.',
    'dashboard.getPass': 'Iegādāties abonementu',
    'dashboard.nextBooking': 'Nākamā rezervācija',
    'dashboard.noBooking': 'Nav gaidāmu rezervāciju.',
    'dashboard.bookSlot': 'Rezervēt laiku',
    'dashboard.paidConfirmed': 'Apmaksāts · apstiprināts',
    'dashboard.viewBookings': 'Visas rezervācijas',

    // Pass tickets (components/pass-ticket.tsx, lib/plans.ts)
    'subscriptions.perMonthShort': '/ mēnesī',
    'subscriptions.perYearShort': '/ gadā',
    'subscriptions.unlimitedEntries': 'Neierobežotas ieejas',
    'subscriptions.mostPicked': 'Populārākais',
    'subscriptions.cancelAnyTime': 'Atceliet jebkurā laikā',
    'subscriptions.admitOne': 'Ieeja · QR',

    // Chat & scan extras
    'chat.subtitle':
        'Jautājiet darbiniekiem jebko vai parunājiet ar citiem braucējiem.',
    'chatThread.roleAdmin': 'Administrators',
    'chatThread.roleStaff': 'Darbinieks',
    'scan.mode': 'Skenēšanas režīms',
    'scan.hint': 'Pavērsiet kameru pret klienta QR kodu',
    'scan.waiting': 'Gaida kodu…',
    'scan.notAllowed': 'Nav atļauts',

    // Auth page headlines (the dark left panel)
    'auth.login.heroLead': 'Laiks',
    'auth.login.heroHighlight': 'vēl vienai',
    'auth.login.heroTail': 'sesijai?',
    'auth.login.blurb':
        'Pieslēdzieties, lai saņemtu ieejas QR kodu, pārvaldītu abonementu un rezervētu laiku savai grupai.',
    'auth.login.noteEyebrow': 'Ieejas karte',
    'auth.login.note': 'Jūsu QR ir iekšā',
    'auth.login.justLooking': 'Tikai paskatīties?',
    'auth.login.watchLive': 'Skatīties tiešraidi',
    'auth.register.heroLead': 'Pirmo reizi šeit?',
    'auth.register.heroHighlight': 'Ienāciet.',
    'auth.register.blurb': 'Viens konts — viss, kas parkā vajadzīgs.',
    'auth.register.perkQr': 'Savs QR kods',
    'auth.register.perkBook': 'Grupu rezervācijas',
    'auth.register.perkChat': 'Čats ar darbiniekiem',
    'auth.register.perkLive': 'Parka tiešraide',
    'auth.forgotPassword.heroLead': 'Aizmirsāt',
    'auth.forgotPassword.heroHighlight': 'paroli?',
    'auth.forgotPassword.blurb':
        'Gadās ikvienam. Celieties augšā — atsūtīsim saiti jaunas paroles iestatīšanai.',
    'auth.forgotPassword.note': 'Saite nonāks jūsu e-pastā',
    'auth.resetPassword.heroLead': 'Jauna',
    'auth.resetPassword.heroHighlight': 'parole.',
    'auth.resetPassword.blurb':
        'Izvēlieties jaunu paroli un varat atkal braukt.',
    'auth.verifyEmail.heroLead': 'Vēl viens',
    'auth.verifyEmail.heroHighlight': 'solis.',
    'auth.verifyEmail.note': 'Pārbaudiet e-pastu',

    // Chat channels, account settings
    'chat.channels': 'Kanāli',
    'chat.general': 'vispārīgi',
    'chat.yourGroups': 'Jūsu grupas',
    'chat.noGroups': 'Rezervējiet laiku, lai iegūtu privātu grupas čatu.',
    'chat.editProfile': 'Rediģēt profilu',
    'chatThread.channelStart': 'Šis ir kanāla #{channel} sākums.',
    'chatThread.messageTo': 'Rakstiet kanālā #{channel}',
    'chatThread.today': 'Šodien',
    'chatThread.yesterday': 'Vakar',
    'chatThread.jumpToNew': 'Jaunas ziņas ({count}) ↓',
    'chatThread.pinnedCount': 'Piespraustas ({count})',
    'chatThread.sendHint': 'Enter — nosūtīt · Shift+Enter — jauna rinda',
    'chatThread.you': 'jūs',
    'chatThread.closePinned': 'Aizvērt piespraustās ziņas',
    'nav.account': 'Konts',
    'settings.tabs.profile': 'Profils',
    'settings.tabs.password': 'Parole',
    'settings.profile.title': 'Profils',
    'settings.profile.subtitle':
        'Mainiet vārdu, ko redz citi braucēji un darbinieki.',
    'settings.profile.preview': 'Kā jūs izskatāties čatā',
    'settings.profile.emailHint':
        'Jūsu e-pasts ir arī jūsu lietotājvārds, tāpēc to šeit nevar mainīt.',
    'settings.profile.submit': 'Saglabāt',
    'settings.profile.updated': 'Jūsu vārds ir atjaunināts.',
    'settings.profile.pendingSubmitted':
        'Vārda maiņa iesniegta — tā parādīsies, tiklīdz administrators to apstiprinās.',
    'settings.profile.pendingNotice':
        'Gaida administratora apstiprinājumu: "{name}". Līdz tam visur redzams jūsu pašreizējais vārds.',

    // Toasts
    'toast.tooManyRequests': 'Palēnini nedaudz — tas ir daudz klikšķu.',
};

export default lv;
