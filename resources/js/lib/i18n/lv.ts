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
    'reservations.minGroupSizeHint':
        'Vismaz {min} cilvēki — cena tiek aprēķināta par personu, par stundu.',
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
    'subscriptions.visitsRemaining': 'Atlikuši {count} apmeklējumi',
    'subscriptions.unlimitedSameDay': 'Neierobežota ieeja tajā pašā dienā',
    'subscriptions.visitsCount': '{count} apmeklējumi',
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
    'chatThread.noMessages': 'Vēl nav ziņu — uzrakstiet kaut ko.',
    'chatThread.mute': 'Apklusināt {duration}',
    'chatThread.muteHour': '1 stundu',
    'chatThread.muteDay': '1 dienu',
    'chatThread.muteWeek': '1 nedēļu',
    'chatThread.delete': 'Dzēst',
    'chatThread.slowModeStaff':
        'Ir ieslēgts lēnais režīms — personālam tas neattiecas.',
    'chatThread.slowModeCustomer':
        'Ir ieslēgts lēnais režīms — viena ziņa ik pēc {seconds}s.',
    'chatThread.mutedUntil': 'Jūs esat apklusināts čatā līdz {date}.',
    'chatThread.muted': 'Jūs esat apklusināts čatā.',
    'chatThread.placeholder': 'Uzrakstiet kaut ko…',
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
};

export default lv;
