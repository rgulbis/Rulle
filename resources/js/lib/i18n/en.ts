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
    'reservations.minGroupSizeHint':
        'Minimum {min} people — priced per person, per hour.',
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
    'subscriptions.visitsRemaining': '{count} visit(s) remaining',
    'subscriptions.unlimitedSameDay': 'Unlimited entries, same day',
    'subscriptions.visitsCount': '{count} visits',
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
    'chatThread.noMessages': 'No messages yet — say something.',
    'chatThread.mute': 'Mute {duration}',
    'chatThread.muteHour': '1 hour',
    'chatThread.muteDay': '1 day',
    'chatThread.muteWeek': '1 week',
    'chatThread.delete': 'Delete',
    'chatThread.slowModeStaff': "Slow mode is on — it doesn't apply to staff.",
    'chatThread.slowModeCustomer':
        'Slow mode is on — one message every {seconds}s.',
    'chatThread.mutedUntil': "You're muted from chat until {date}.",
    'chatThread.muted': "You're muted from chat.",
    'chatThread.placeholder': 'Say something…',
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
} as const;

export default en;
export type TranslationKey = keyof typeof en;
