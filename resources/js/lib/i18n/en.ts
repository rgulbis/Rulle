// The canonical key list — every other language dictionary is typed against
// this one's keys, so a missing translation is a compile error, not a
// silent fallback to English at runtime.
const en = {
    // Nav (app-layout.tsx)
    'nav.brand': 'Rullē',
    'nav.dashboard': 'Dashboard',
    'nav.subscriptions': 'Subscriptions',
    'nav.reservations': 'Reservations',
    'nav.scan': 'Scan',
    'nav.chat': 'Chat',
    'nav.livestream': 'Livestream',
    'nav.admin': 'Admin',
    'nav.changePassword': 'Change password',
    'nav.logOut': 'Log out',
    'nav.logIn': 'Log in',

    // Livestream (pages/livestream/index.tsx)
    'livestream.title': 'Livestream',
    'livestream.subtitle': 'A live look at the park. Not recorded or saved.',
    'livestream.checkedInCount_one': '{count} person checked in',
    'livestream.checkedInCount_other': '{count} people checked in',
    'livestream.noReservationsToday': 'No reservations today.',
    'livestream.reservedToday': 'Reserved today: {ranges}',
    'livestream.offline':
        "Camera feed isn't available right now — check back later.",
    'livestream.unsupportedBrowser': "Your browser can't play this stream.",

    // Dashboard (pages/dashboard.tsx)
    'dashboard.welcome': 'Welcome, {name}.',
    'dashboard.loggedIn': "You're logged in.",
    'dashboard.verificationSent':
        'A new verification link has been sent to your email address.',
    'dashboard.pleaseVerify':
        'Please verify your email address to subscribe or make purchases.',
    'dashboard.resendVerification': 'Resend verification email',
    'dashboard.checkedIn': 'Checked in',
    'dashboard.notCheckedIn': 'Not checked in',
    'dashboard.verifyForQrCode': 'Verify your email to get your entry QR code.',

    // Reservations (pages/reservations/index.tsx)
    'reservations.title': 'Reserve the park',
    'reservations.statusComplete':
        'Reservation confirmed — the park is yours for that time.',
    'reservations.statusIncomplete':
        "That checkout wasn't completed, so nothing was reserved.",
    'reservations.statusCancelled': 'Reservation cancelled.',
    'reservations.statusCancelledRefunded':
        'Reservation cancelled and refunded.',
    'reservations.statusCancelledNoRefund':
        'Reservation cancelled, but it was too close to the start time to be refunded.',
    'reservations.statusCannotCancel':
        'That reservation has already started and can no longer be cancelled.',
    'reservations.statusSlotTaken':
        "Someone else booked that time first while you were paying — you've been refunded and the reservation was cancelled. Pick another time.",
    'reservations.reserveATime': 'Reserve a time',
    'reservations.date': 'Date',
    'reservations.time': 'Time',
    'reservations.timelineHint':
        '{min}–{max} minutes, in 15-minute steps. The dotted line shows typically busy hours; the red block is already reserved.',
    'reservations.groupSize': 'Group size (people)',
    'reservations.groupSizeHint':
        '{min}–{max} people — priced per person, per hour.',
    'reservations.price': 'Price: {amount}',
    'reservations.reserveAndPay': 'Reserve and pay',
    'reservations.upcoming': 'Upcoming reservations',
    'reservations.upcomingHint':
        "The park is privately reserved during these times — everyone else's entry is paused until they end. Click a day to see its reserved times, or to book that day above.",
    'reservations.mine': 'My reservations',
    'reservations.noneUpcoming': "You don't have any upcoming reservations.",
    'reservations.peopleCount': '{count} people',
    'reservations.namedOf': '{named} of {total} named',
    'reservations.groupChat': 'Group chat',
    'reservations.finishPayment': 'Finish payment',
    'reservations.cancel': 'Cancel reservation',
    'reservations.confirmCancel': 'Cancel this reservation?',
    'reservations.remove': 'Remove',
    'reservations.groupFull':
        "This reservation's group size is full — remove someone or make a new reservation for more people.",
    'reservations.searchPlaceholder': 'Search by name or email to add a friend',
    'reservations.searching': 'Searching…',
    'reservations.add': 'Add',
    'reservations.selectDayHint': 'Select a day to see reserved times.',
    'reservations.noneThatDay': 'No reservations that day.',
    'reservations.previousMonth': 'Previous month',
    'reservations.nextMonth': 'Next month',
    'reservations.chooseStart': 'Click to choose a start time',
    'reservations.chooseEnd': '{start} – click an end time',
    'reservations.selectionSummary': '{start} – {end} ({minutes} min)',
    'reservations.reset': 'Reset',
    'reservations.startTime': 'Start time',
    'reservations.startTimePlaceholder': 'Choose a start time',
    'reservations.duration': 'Duration',
    'reservations.durationOption': '{minutes} min',
    'reservations.durationPlaceholder': 'Choose a start time first',
    'reservations.status.pending': 'pending',
    'reservations.status.active': 'active',
    'reservations.status.cancelled': 'cancelled',

    // Reservation group chat (pages/reservations/chat.tsx)
    'reservationChat.back': '← Back to reservations',
    'reservationChat.title': 'Group chat — {range}',
    'reservationChat.subtitle':
        "Only visible to your group — the owner and the friends they've added.",

    // Subscriptions (pages/subscriptions/index.tsx)
    'subscriptions.title': 'Subscriptions',
    'subscriptions.statusPurchaseComplete':
        'Purchase complete — your access is now active.',
    'subscriptions.statusPurchaseIncomplete':
        "That checkout wasn't completed, so nothing was activated.",
    'subscriptions.statusPurchaseCancelled': 'Checkout was cancelled.',
    'subscriptions.statusSubscriptionCancelled':
        'Your subscription has been cancelled.',
    'subscriptions.statusPriceUpdated':
        'Your subscription now uses the new price.',
    'subscriptions.cancelledUntil': 'Cancelled — access ends {date}.',
    'subscriptions.periodEnd': 'at period end',
    'subscriptions.active': 'Active subscription ({status})',
    'subscriptions.cancelSubscription': 'Cancel subscription',
    'subscriptions.confirmCancel':
        "Cancel your subscription? You'll keep access until the current billing period ends.",
    'subscriptions.priceChanged':
        "This plan's price has changed: you're on {current}, the current price is {new}.",
    'subscriptions.switchToNewPrice': 'Switch to new price',
    'subscriptions.unlimitedToday': 'Unlimited entries today',
    'subscriptions.visitsRemaining_one': '{count} visit remaining',
    'subscriptions.visitsRemaining_other': '{count} visits remaining',
    'subscriptions.unlimitedSameDay': 'Unlimited entries, same day',
    'subscriptions.visitsCount_one': '{count} visit',
    'subscriptions.visitsCount_other': '{count} visits',
    'subscriptions.alreadySubscribed': 'Already subscribed',
    'subscriptions.buy': 'Buy',
    'subscriptions.subscribe': 'Subscribe',
    'subscriptions.perMonth': '{amount} / month',
    'subscriptions.perYear': '{amount} / year',
    'subscriptions.none': 'No plans are available yet.',

    // Chat (pages/chat/index.tsx)
    'chat.title': 'Chat',

    // Chat thread component (components/chat-thread.tsx)
    'chatThread.pinned': 'Pinned',
    'chatThread.unpin': 'Unpin',
    'chatThread.pin': 'Pin',
    'chatThread.moreActions': 'More actions',
    'chatThread.noMessages': 'No messages yet — say something.',
    'chatThread.mute': 'Mute {duration}',
    'chatThread.muteHour': '1 hour',
    'chatThread.muteDay': '1 day',
    'chatThread.muteWeek': '1 week',
    'chatThread.muteSuccess': 'Muted {name} for {duration}.',
    'chatThread.muteFailed': 'Could not mute that person.',
    'chatThread.unmute': 'Unmute',
    'chatThread.unmuteSuccess': 'Unmuted {name}.',
    'chatThread.unmuteFailed': 'Could not unmute that person.',
    'chatThread.reply': 'Reply',
    'chatThread.replyingTo': 'Replying to {name}',
    'chatThread.cancelReply': 'Cancel reply',
    'chatThread.delete': 'Delete',
    'chatThread.slowModeStaff': "Slow mode is on — it doesn't apply to staff.",
    'chatThread.slowModeCustomer':
        'Slow mode is on — one message every {seconds}s.',
    'chatThread.mutedUntil': "You're muted from chat until {date}.",
    'chatThread.muted': "You're muted from chat.",
    'chatThread.placeholder': 'Say something…',
    'chatThread.slowModeWait':
        'Slow mode is on — wait {seconds}s before sending another message.',
    'chatThread.wait': 'Wait {seconds}s',
    'chatThread.send': 'Send',
    'chatThread.sendFailed': 'Could not send that message.',

    // Staff scan (pages/staff/scan.tsx)
    'scan.title': 'Scan a member QR code',
    'scan.entry': 'Entry',
    'scan.exit': 'Exit',
    'scan.checkedIn': 'Checked in',
    'scan.checkedOut': 'Checked out',
    'scan.unreachable': 'Could not reach the server.',

    // Settings / password (pages/settings/password.tsx)
    'settings.password.title': 'Change password',
    'settings.password.subtitle':
        'Ensure your account is using a long, random password to stay secure.',
    'settings.password.updated': 'Your password has been updated.',
    'settings.password.current': 'Current password',
    'settings.password.new': 'New password',
    'settings.password.confirm': 'Confirm new password',
    'settings.password.submit': 'Update password',

    // Auth — shared
    'auth.email': 'Email',
    'auth.password': 'Password',

    // Auth — login
    'auth.login.title': 'Log in',
    'auth.login.description': 'Enter your email and password to sign in.',
    'auth.login.forgotPassword': 'Forgot password?',
    'auth.login.rememberMe': 'Remember me',
    'auth.login.submit': 'Log in',
    'auth.login.noAccount': "Don't have an account?",
    'auth.login.signUp': 'Sign up',

    // Auth — register
    'auth.register.title': 'Create an account',
    'auth.register.description': 'Enter your details below to sign up.',
    'auth.register.name': 'Name',
    'auth.register.confirmPassword': 'Confirm password',
    'auth.register.submit': 'Sign up',
    'auth.register.haveAccount': 'Already have an account?',
    'auth.register.logIn': 'Log in',

    // Auth — forgot password
    'auth.forgotPassword.title': 'Forgot password',
    'auth.forgotPassword.description':
        "Enter your email and we'll send you a link to reset your password.",
    'auth.forgotPassword.submit': 'Email password reset link',
    'auth.forgotPassword.remembered': 'Remembered your password?',
    'auth.forgotPassword.logIn': 'Log in',

    // Auth — reset password
    'auth.resetPassword.title': 'Reset password',
    'auth.resetPassword.description': 'Enter your new password below.',
    'auth.resetPassword.confirmPassword': 'Confirm new password',
    'auth.resetPassword.submit': 'Reset password',

    // Auth — verify email
    'auth.verifyEmail.title': 'Verify email',
    'auth.verifyEmail.description':
        'Thanks for signing up! Before getting started, please verify your email address by clicking the link we just emailed to you.',
    'auth.verifyEmail.sent':
        'A new verification link has been sent to the email address you provided during registration.',
    'auth.verifyEmail.resend': 'Resend verification email',
    'auth.verifyEmail.logOut': 'Log out',

    // Nav, footer & park status (layouts/app-layout.tsx)
    'nav.signUp': 'Sign up',
    'nav.staffTag': 'Staff',
    'nav.main': 'Main navigation',
    'nav.openMenu': 'Open menu',
    'nav.closeMenu': 'Close menu',
    'nav.language': 'Language',
    'park.openUntil': 'Open · until {time}',
    'park.closedOpensAt': 'Closed · opens {time}',
    'park.stickerOpen': 'Open till {time}',
    'park.stickerClosed': 'Closed now',
    'footer.hours': 'Hours',
    'footer.everyDay': 'Every day, {opening}–{closing}',
    'footer.park': 'Park',

    // Shared form bits (components/form-controls.tsx, password-checklist.tsx)
    'form.showPassword': 'Show password',
    'form.hidePassword': 'Hide password',
    'form.emailPlaceholder': 'you@example.com',
    'form.passwordsMatch': 'Passwords match',
    'form.passwordsDontMatch': "Passwords don't match yet",
    'form.rule.length': 'At least 10 characters',
    'form.rule.mixedCase': 'Upper and lower case',
    'form.rule.number': 'A number',
    'form.rule.symbol': 'A symbol',
    'form.rule.met': '(done)',
    'form.rule.notMet': '(not yet)',

    // Live headcount (components/live.tsx)
    'occupancy.quiet': 'quiet',
    'occupancy.gettingBusy': 'getting busy',
    'occupancy.busy': 'busy',
    'occupancy.meterLabel': 'How busy: {level} out of 10',
    'livestream.live': 'LIVE',
    'livestream.inParkNow': 'In the park now',
    'livestream.today': 'Today',
    'livestream.openHours': 'Open {opening}–{closing}',
    'livestream.cta': 'Looks good? Come ride.',

    // Home (pages/welcome.tsx)
    'home.title': 'Skatepark',
    'home.heroLead': 'Ride more.',
    'home.heroQueue': 'Queue',
    'home.heroHighlight': 'less.',
    'home.intro':
        'Ramps, bowls and rails for every skill level — from your first jump to the hardest tricks. An indoor park in Cēsis, open every day whatever the weather.',
    'home.seePasses': 'See passes',
    'home.watchLive': 'Watch the park live',
    'home.parkRightNow': 'The park right now',
    'home.skatingNow': 'skating now',
    'home.camera': 'Camera',
    'home.countedFromCheckIns': 'Counted from check-ins at the door',
    'home.ticker.hours': 'Open today {opening}–{closing}',
    'home.ticker.skating': '{count} skating right now',
    'home.ticker.reserved': 'Privately booked today {ranges}',
    'home.ticker.noReservations': 'No private bookings today',
    'home.ticker.howItWorks': 'Buy online · scan at the door',
    'home.step1.title': 'Pick a pass',
    'home.step1.text':
        'Choose one of the available passes. Pay online by card.',
    'home.step2.title': 'Scan at the door',
    'home.step2.text':
        "Your QR code lives in your account. Staff scan it and you're in.",
    'home.step3.title': 'Skate',
    'home.step3.text': 'Questions? Message the staff in the chat any time.',
    'home.passesTitle': 'Passes',
    'home.passesIntro':
        'Your account has one personal QR code that works for whichever pass you hold. Monthly and yearly passes renew automatically — cancel whenever.',
    'home.crewLead': 'Bring',
    'home.crewTail': 'the crew.',
    'home.crewText':
        'Book the whole park for your group — birthdays, school classes, teams. {price} per person per hour, from {min} people.',
    'home.bookSlot': 'Book a slot',
    'home.todayTitle': 'Today at the park',
    'home.todayFree':
        'No private bookings today — the park is open to everyone.',
    'home.privatelyBooked': 'Booked',

    // Dashboard cards (pages/dashboard.tsx)
    'dashboard.entryPass': 'Entry pass',
    'dashboard.showAtDesk': 'Show this at the front desk',
    'dashboard.brightnessHint':
        'Turn your screen brightness up so it scans first try.',
    'dashboard.subscription': 'Subscription',
    'dashboard.accessEnds': 'Cancelled — access ends {date}',
    'dashboard.renewsAutomatically': 'Renews automatically',
    'dashboard.renewsOn': 'Renews automatically on {date}',
    'dashboard.yourPass': 'Your pass',
    'dashboard.manage': 'Manage',
    'dashboard.noPass': "You don't have an active pass yet.",
    'dashboard.getPass': 'Get a pass',
    'dashboard.nextBooking': 'Next booking',
    'dashboard.noBooking': 'No upcoming bookings.',
    'dashboard.bookSlot': 'Book a slot',
    'dashboard.paidConfirmed': 'Paid · confirmed',
    'dashboard.viewBookings': 'View bookings',

    // Pass tickets (components/pass-ticket.tsx, lib/plans.ts)
    'subscriptions.perMonthShort': '/ month',
    'subscriptions.perYearShort': '/ year',
    'subscriptions.unlimitedEntries': 'Unlimited entries',
    'subscriptions.mostPicked': 'Most picked',
    'subscriptions.cancelAnyTime': 'Cancel any time',
    'subscriptions.admitOne': 'Admit one · QR',

    // Chat & scan extras
    'chat.subtitle': 'Ask the staff anything, or chat with other riders.',
    'chatThread.roleAdmin': 'Admin',
    'chatThread.roleStaff': 'Employee',
    'scan.mode': 'Scan mode',
    'scan.hint': "Point the camera at the customer's QR code",
    'scan.waiting': 'Waiting for a code…',
    'scan.notAllowed': 'Not allowed',

    // Auth page headlines (the dark left panel)
    'auth.login.heroLead': 'Back for',
    'auth.login.heroHighlight': 'another',
    'auth.login.heroTail': 'session?',
    'auth.login.blurb':
        'Log in to get your entry QR code, manage your pass and book a slot for the crew.',
    'auth.login.noteEyebrow': 'Entry pass',
    'auth.login.note': 'Your QR is inside',
    'auth.login.justLooking': 'Just looking?',
    'auth.login.watchLive': 'Watch the park live',
    'auth.register.heroLead': 'New here?',
    'auth.register.heroHighlight': 'Drop in.',
    'auth.register.blurb': 'One account gets you everything at the park.',
    'auth.register.perkQr': 'Your own QR pass',
    'auth.register.perkBook': 'Book group slots',
    'auth.register.perkChat': 'Chat with staff',
    'auth.register.perkLive': 'Watch the park live',
    'auth.forgotPassword.heroLead': 'Bailed on your',
    'auth.forgotPassword.heroHighlight': 'password?',
    'auth.forgotPassword.blurb':
        "Happens to everyone. Get back up — we'll email you a link to set a new one.",
    'auth.forgotPassword.note': 'The link lands in your inbox',
    'auth.resetPassword.heroLead': 'Fresh',
    'auth.resetPassword.heroHighlight': 'start.',
    'auth.resetPassword.blurb':
        "Pick a new password and you're back on the board.",
    'auth.verifyEmail.heroLead': 'One more',
    'auth.verifyEmail.heroHighlight': 'step.',
    'auth.verifyEmail.note': 'Check your inbox',

    // Chat channels, account settings
    'chat.channels': 'Channels',
    'chat.general': 'general',
    'chat.yourGroups': 'Your groups',
    'chat.noGroups': 'Book a slot to get a private group chat.',
    'chat.editProfile': 'Edit profile',
    'chatThread.channelStart': 'This is the start of #{channel}.',
    'chatThread.messageTo': 'Message #{channel}',
    'chatThread.today': 'Today',
    'chatThread.yesterday': 'Yesterday',
    'chatThread.jumpToNew': 'New messages ({count}) ↓',
    'chatThread.pinnedCount': 'Pinned ({count})',
    'chatThread.sendHint': 'Enter to send · Shift+Enter for a new line',
    'chatThread.you': 'you',
    'chatThread.closePinned': 'Close pinned messages',
    'nav.account': 'Account',
    'settings.tabs.profile': 'Profile',
    'settings.tabs.password': 'Password',
    'settings.profile.title': 'Profile',
    'settings.profile.subtitle': 'Change the name other riders and staff see.',
    'settings.profile.preview': 'How you appear in chat',
    'settings.profile.emailHint':
        "Your email is also your login, so it can't be changed here.",
    'settings.profile.submit': 'Save',
    'settings.profile.updated': 'Your name has been updated.',
    'settings.profile.pendingSubmitted':
        "Name change submitted — it'll show up once an admin approves it.",
    'settings.profile.pendingNotice':
        'Waiting on admin approval: "{name}". Your current name still shows everywhere until then.',

    // Toasts
    'toast.tooManyRequests': "Slow down a little — that's a lot of clicks.",
} as const;

export default en;
export type TranslationKey = keyof typeof en;
