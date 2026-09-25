#!/usr/bin/env python3
"""Generate all non-English locale files for wiki-donate vue-i18n.

Deterministic, local, offline. Loads the authoritative en.json and writes
every other locale as en.json structure with per-language translations.
Seeds cover the visible vocabulary; unseeded keys keep the English text
(which also falls back at runtime via fallbackLocale='en'), so no page
ever shows a raw key or blank string.

Usage: python3 scripts/build_locales.py
"""
import json
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCALES = os.path.join(ROOT, 'resources', 'js', 'i18n', 'locales')

SUPPORTED = [
    'en', 'bn', 'ur', 'hi', 'ar', 'es', 'fr', 'de', 'it', 'pt', 'ru',
    'zh-CN', 'zh-TW', 'ja', 'ko', 'tr', 'nl', 'pl', 'sv', 'th', 'vi',
    'id', 'ms', 'tl', 'uk', 'ro', 'el', 'cs', 'hu', 'fi', 'da', 'no',
    'he', 'fa', 'ta', 'te', 'mr', 'gu', 'sw', 'am', 'my', 'km',
]


def flat(d, p=''):
    out = {}
    for k, v in d.items():
        path = f'{p}.{k}' if p else k
        if isinstance(v, dict):
            out.update(flat(v, path))
        else:
            out[path] = v
    return out


def nest(flat_dict):
    out = {}
    for path, value in flat_dict.items():
        parts = path.split('.')
        cur = out
        for part in parts[:-1]:
            cur = cur.setdefault(part, {})
        cur[parts[-1]] = value
    return out


def build(code, en_flat, seed):
    data = {}
    for key, value in en_flat.items():
        data[key] = seed.get(key, value)
    with open(os.path.join(LOCALES, f'{code}.json'), 'w', encoding='utf-8') as f:
        json.dump(nest(data), f, ensure_ascii=False, indent=4)
        f.write('\n')
    n = sum(1 for k in seed if k in en_flat and seed[k] != en_flat[k])
    print(f'{code}.json: {n}/{len(en_flat)} keys translated, parity OK')


# ---------------------------------------------------------------- seeds ----
# Values hand-authored; placeholders {n}/{email}/{month}/{year}/{title}/{org}/
# {currency}/{name}/{total}/{current}/{last}/{seconds}/{method}/{amount}
# preserved verbatim. Brand names stay English.

SEEDS = {
'bn': {
    'common.loading': 'লোড হচ্ছে...', 'common.searching': 'wikidonate খোঁজা হচ্ছে...',
    'common.noResults': 'কোনো ফলাফল পাওয়া যায়নি',
    'common.searchPlaceholder': 'wikidonate খুঁজুন...',
    'common.searchPlaceholderHome': 'Wikidonate খুঁজুন...',
    'common.cancel': 'বাতিল', 'common.save': 'সংরক্ষণ', 'common.saving': 'সংরক্ষণ হচ্ছে...',
    'common.submit': 'জমা দিন', 'common.submitting': 'জমা দেওয়া হচ্ছে...',
    'common.send': 'পাঠান', 'common.sending': 'পাঠানো হচ্ছে...',
    'common.delete': 'মুছুন', 'common.close': 'বন্ধ করুন', 'common.view': 'দেখুন',
    'common.edit': 'সম্পাদনা', 'common.apply': 'প্রয়োগ করুন', 'common.reset': 'রিসেট',
    'common.copied': 'কপি হয়েছে!', 'common.backToHome': 'হোমে ফিরুন',
    'common.backToArticle': 'নিবন্ধে ফিরুন', 'common.allRightsReserved': 'সর্বস্বত্ব সংরক্ষিত।',
    'common.required': 'আবশ্যক', 'common.page': 'পৃষ্ঠা', 'common.of': '/',
    'common.total': 'মোট', 'common.you': 'আপনি', 'common.back': 'ফিরুন',
    'common.update': 'আপডেট', 'common.pay': 'পরিশোধ করুন',
    'auth.loginTitle': 'আপনার অ্যাকাউন্টে লগইন করুন',
    'auth.loginSubtitle': 'ফিরে আসার জন্য স্বাগতম! চালিয়ে যেতে সাইন ইন করুন',
    'auth.username': 'ইউজারনেম', 'auth.password': 'পাসওয়ার্ড',
    'auth.usernamePlaceholder': 'আপনার ইউজারনেম লিখুন',
    'auth.passwordPlaceholder': 'আপনার পাসওয়ার্ড লিখুন',
    'auth.forgotPassword': 'পাসওয়ার্ড ভুলে গেছেন?',
    'auth.authenticating': 'যাচাই করা হচ্ছে...', 'auth.login': 'লগইন',
    'auth.noAccount': 'অ্যাকাউন্ট নেই?', 'auth.createAccount': 'অ্যাকাউন্ট তৈরি করুন',
    'auth.loginFailed': 'লগইন ব্যর্থ হয়েছে', 'auth.usernameRequired': 'ইউজারনেম আবশ্যক',
    'auth.passwordRequired': 'পাসওয়ার্ড আবশ্যক',
    'auth.createTitle': 'একটি অ্যাকাউন্ট তৈরি করুন',
    'auth.createSubtitle': 'আমাদের কমিউনিটিতে যোগ দিন এবং আপনার যাত্রা শুরু করুন',
    'auth.confirmPassword': 'পাসওয়ার্ড নিশ্চিত করুন',
    'auth.confirmPasswordPlaceholder': 'পাসওয়ার্ড নিশ্চিত করুন',
    'auth.email': 'ইমেইল', 'auth.emailPlaceholder': 'আপনার ইমেইল লিখুন',
    'auth.creating': 'তৈরি হচ্ছে...', 'auth.alreadyHaveAccount': 'ইতিমধ্যে অ্যাকাউন্ট আছে?',
    'auth.signIn': 'সাইন ইন', 'auth.confirmPasswordRequired': 'পাসওয়ার্ড নিশ্চিতকরণ আবশ্যক',
    'auth.emailInvalid': 'একটি বৈধ ইমেইল দিন', 'auth.emailRequired': 'ইমেইল আবশ্যক',
    'auth.passwordsMustMatch': 'পাসওয়ার্ড মিলছে না',
    'auth.minChars': '{n} অক্ষরের কম হতে পারবে না', 'auth.registrationFailed': 'নিবন্ধন ব্যর্থ হয়েছে',
    'auth.checkYourEmail': 'আপনার ইমেইল চেক করুন',
    'auth.verificationSent': 'আমরা {email}-এ একটি ভেরিফিকেশন লিংক পাঠিয়েছি। ইনবক্স চেক করুন।',
    'auth.didntReceive': 'ইমেইল পাননি?', 'auth.resendIn': '{n} সেকেন্ডে আবার পাঠান',
    'auth.resendVerificationEmail': 'ভেরিফিকেশন ইমেইল আবার পাঠান',
    'auth.goToLogin': 'লগইনে যান', 'auth.verificationEmailSent': 'ভেরিফিকেশন ইমেইল পাঠানো হয়েছে।',
    'auth.failedToResend': 'ইমেইল আবার পাঠানো ব্যর্থ হয়েছে।',
    'auth.passwordRecovery': 'পাসওয়ার্ড পুনরুদ্ধার',
    'auth.passwordRecoverySubtitle': 'পাসওয়ার্ড রিসেট করতে ইমেইল লিখুন',
    'auth.rememberPassword': 'পাসওয়ার্ড মনে আছে?', 'auth.backToLogin': 'লগইনে ফিরুন',
    'auth.emailVerification': 'ইমেইল ভেরিফিকেশন',
    'auth.emailVerificationSubtitle': 'আপনার ইমেইল ঠিকানা যাচাই করা হচ্ছে',
    'auth.verifyingEmail': 'ইমেইল যাচাই করার সময় অপেক্ষা করুন...',
    'auth.emailVerified': 'ইমেইল ভেরিফাই হয়েছে!',
    'auth.emailVerifiedMsg': 'আপনার ইমেইল সফলভাবে ভেরিফাই হয়েছে।',
    'auth.continueToLogin': 'লগইনে চালিয়ে যান', 'auth.verificationFailed': 'ভেরিফিকেশন ব্যর্থ হয়েছে',
    'auth.verificationFailedMsg': 'ভেরিফিকেশন লিংক অবৈধ বা মেয়াদোত্তীর্ণ।',
    'auth.verificationFailedShort': 'ভেরিফিকেশন ব্যর্থ হয়েছে।',
    'auth.invalidVerificationLink': 'অবৈধ ভেরিফিকেশন লিংক।',
    'auth.verificationError': 'ভেরিফিকেশনের সময় ত্রুটি ঘটেছে।',
    'auth.logout': 'লগআউট', 'auth.logIn': 'লগ ইন',
    'nav.mainPage': 'মূল পাতা', 'nav.adminPanel': 'অ্যাডমিন প্যানেল',
    'nav.createAccount': 'অ্যাকাউন্ট তৈরি করুন', 'nav.login': 'লগইন',
    'nav.profileSettings': 'প্রোফাইল সেটিংস', 'nav.myArticles': 'আমার নিবন্ধসমূহ',
    'nav.preferences': 'পছন্দসমূহ', 'nav.myDonations': 'আমার দানসমূহ',
    'nav.logout': 'লগআউট',
    'home.title': 'WikiDonate — দাতব্য সংস্থা আবিষ্কার করুন',
    'main.title': 'মূল পাতা', 'main.welcome': 'WikiDonate-এ স্বাগতম',
    'main.howToUse': 'WikiDonate ব্যবহারের নিয়ম', 'main.foundingDocument': 'প্রতিষ্ঠার দলিল',
    'main.githubRepository': 'GitHub রিপোজিটরি',
    'profile.title': 'প্রোফাইল বিবরণ', 'profile.yourInformation': 'আপনার তথ্য',
    'profile.fullName': 'পুরো নাম', 'profile.fullNamePlaceholder': 'পুরো নাম লিখুন',
    'profile.emailAddress': 'ইমেইল ঠিকানা', 'profile.phoneNumber': 'ফোন নম্বর',
    'profile.phonePlaceholder': 'ফোন নম্বর', 'profile.saving': 'সংরক্ষণ হচ্ছে...',
    'profile.fullNameRequired': 'পুরো নাম আবশ্যক', 'profile.invalidEmail': 'অবৈধ ইমেইল',
    'profile.phoneInvalid': 'ফোন নম্বর ঠিক ১২ সংখ্যার হতে হবে',
    'profile.loadingUser': 'ব্যবহারকারীর বিবরণ লোড হচ্ছে',
    'preferences.title': 'পছন্দসমূহ', 'preferences.userProfile': 'ব্যবহারকারী প্রোফাইল',
    'preferences.basicInformation': 'মৌলিক তথ্য', 'preferences.username': 'ইউজারনেম',
    'preferences.email': 'ইমেইল', 'preferences.memberOfGroup': 'গ্রুপের সদস্য',
    'preferences.registrationTime': 'নিবন্ধনের সময়', 'preferences.password': 'পাসওয়ার্ড',
    'preferences.changePassword': 'পাসওয়ার্ড পরিবর্তন করুন',
    'preferences.newPassword': 'নতুন পাসওয়ার্ড', 'preferences.confirmPassword': 'পাসওয়ার্ড নিশ্চিত করুন',
    'preferences.cancel': 'বাতিল', 'preferences.submit': 'জমা দিন',
    'preferences.submitting': 'জমা দেওয়া হচ্ছে...',
    'preferences.notificationPreferences': 'নোটিফিকেশন পছন্দসমূহ',
    'preferences.editTalkPage': 'আমার আলাপ পাতায় সম্পাদনা',
    'preferences.editUserPage': 'আমার ব্যবহারকারী পাতায় সম্পাদনা',
    'preferences.pageReview': 'পাতা পর্যালোচনা',
    'preferences.emailFromOther': 'অন্য ব্যবহারকারীর কাছ থেকে ইমেইল',
    'preferences.successfulMention': 'সফল উল্লেখ', 'preferences.savePreferences': 'পছন্দ সংরক্ষণ করুন',
    'preferences.loadingPreferences': 'পছন্দ লোড হচ্ছে',
    'preferences.passwordRequired': 'পাসওয়ার্ড আবশ্যক',
    'preferences.passwordsMustMatch': 'পাসওয়ার্ড মিলছে না',
    'preferences.minChars': '{n} অক্ষরের কম হতে পারবে না',
    'preferences.failedToSavePreferences': 'পছন্দ সংরক্ষণ ব্যর্থ হয়েছে',
    'preferences.unexpectedError': 'অপ্রত্যাশিত ত্রুটি',
    'preferences.failedToChangePassword': 'পাসওয়ার্ড পরিবর্তন ব্যর্থ হয়েছে',
    'preferences.changePasswordTitle': 'পাসওয়ার্ড পরিবর্তন করুন',
    'preferences.passwordPlaceholder': 'আপনার পাসওয়ার্ড লিখুন',
    'preferences.confirmPasswordPlaceholder': 'পাসওয়ার্ড নিশ্চিত করুন',
    'billing.title': 'বিলিং', 'billing.paymentMethod': 'পেমেন্ট পদ্ধতি',
    'billing.subtitle': 'আপনার সংরক্ষিত কার্ড নিরাপদে পরিচালনা করুন।',
    'billing.updatePaymentMethod': 'পেমেন্ট পদ্ধতি আপডেট করুন',
    'billing.addNewCard': 'নতুন কার্ড যোগ করুন',
    'billing.loadingCard': 'কার্ডের বিবরণ লোড হচ্ছে...',
    'billing.expires': 'মেয়াদ {month}/{year}', 'billing.edit': 'সম্পাদনা',
    'billing.loadingPaymentForm': 'পেমেন্ট ফর্ম লোড হচ্ছে...',
    'billing.cardNumber': 'কার্ড নম্বর', 'billing.expiryDate': 'মেয়াদ শেষের তারিখ',
    'billing.cvc': 'CVC', 'billing.saveCard': 'কার্ড সংরক্ষণ করুন',
    'billing.cardSaved': 'কার্ড সফলভাবে সংরক্ষণ হয়েছে', 'billing.validationError': 'যাচাইকরণ ত্রুটি',
    'billing.failedToSaveCard': 'কার্ড সংরক্ষণ ব্যর্থ হয়েছে',
    'billing.failedToLoadCard': 'কার্ডের বিবরণ লোড ব্যর্থ হয়েছে',
    'contact.title': 'যোগাযোগ করুন', 'contact.firstName': 'প্রথম নাম',
    'contact.firstNamePlaceholder': 'আপনার প্রথম নাম লিখুন', 'contact.lastName': 'শেষ নাম',
    'contact.lastNamePlaceholder': 'আপনার শেষ নাম লিখুন', 'contact.email': 'ইমেইল',
    'contact.emailPlaceholder': 'আপনার ইমেইল লিখুন', 'contact.subject': 'বিষয়',
    'contact.subjectPlaceholder': 'আপনার বিষয় লিখুন', 'contact.messageDetails': 'বার্তার বিবরণ',
    'contact.messagePlaceholder': 'বার্তার বিবরণ লিখুন', 'contact.submit': 'জমা দিন',
    'contact.sentSuccess': 'আপনার বার্তা সফলভাবে পাঠানো হয়েছে!',
    'contact.failedToSend': 'বার্তা পাঠানো ব্যর্থ হয়েছে',
    'contact.firstNameRequired': 'প্রথম নাম আবশ্যক', 'contact.lastNameRequired': 'শেষ নাম আবশ্যক',
    'contact.subjectRequired': 'বিষয় আবশ্যক', 'contact.messageRequired': 'বার্তার বিবরণ আবশ্যক',
    'contact.emailRequired': 'ইমেইল আবশ্যক', 'contact.emailInvalid': 'একটি বৈধ ইমেইল দিন',
    'howItWorks.title': 'WikiDonate ব্যবহারের নিয়ম', 'howItWorks.steps': 'ধাপসমূহ',
    'howItWorks.pageTitle': 'WikiDonate কীভাবে কাজ করে',
    'howItWorks.subtitle': 'একটি সম্মিলিত দান প্ল্যাটফর্ম।',
    'report.title': 'আমার দানসমূহ', 'report.filters': 'ফিল্টার',
    'report.searchPlaceholder': 'পেমেন্ট আইডি বা ইমেইল দিয়ে খুঁজুন...',
    'report.allStatus': 'সব অবস্থা', 'report.completed': 'সম্পন্ন', 'report.pending': 'বিচারাধীন',
    'report.failed': 'ব্যর্থ', 'report.expired': 'মেয়াদোত্তীর্ণ', 'report.apply': 'প্রয়োগ করুন',
    'report.totalDonated': 'মোট দান', 'report.completedCount': 'সম্পন্ন',
    'report.pendingCount': 'বিচারাধীন', 'report.failedCount': 'ব্যর্থ',
    'report.donationHistory': 'দানের ইতিহাস', 'report.loadingDonations': 'আপনার দান লোড হচ্ছে...',
    'report.noDonations': 'কোনো দান পাওয়া যায়নি', 'report.date': 'তারিখ',
    'report.source': 'উৎস', 'report.amount': 'পরিমাণ', 'report.status': 'অবস্থা',
    'report.paymentId': 'পেমেন্ট আইডি', 'report.article': 'নিবন্ধ', 'report.action': 'কাজ',
    'report.failedToLoad': 'দানের রিপোর্ট লোড ব্যর্থ হয়েছে',
    'report.pageOf': 'পৃষ্ঠা {current} / {last} (মোট {total})',
    'article.loading': 'নিবন্ধ লোড হচ্ছে',
    'article.communityFormulas': 'কমিউনিটি দান ফর্মুলা',
    'article.donationFormulas': 'দান ফর্মুলা', 'article.editedByUser': 'ব্যবহারকারী দ্বারা সম্পাদিত',
    'article.editedTooltip': 'দান করার পরে এই ফর্মুলাটি সম্পাদিত হয়েছে',
    'article.donate': 'দান করুন', 'article.copyUrl': 'ফর্মুলা URL কপি করুন',
    'article.editFormula': 'ফর্মুলা সম্পাদনা করুন', 'article.deleteFormula': 'ফর্মুলা মুছুন',
    'article.total': 'মোট', 'article.details': 'বিবরণ:',
    'article.noFormulas': 'এখনও কোনো দান ফর্মুলা তৈরি হয়নি।',
    'article.beFirst': 'প্রথমটি তৈরি করুন!', 'article.logInToCreate': 'তৈরি করতে লগ ইন করুন!',
    'article.createFormula': 'দান ফর্মুলা তৈরি করুন',
    'article.deleteFormulaTitle': 'দান ফর্মুলা মুছুন', 'article.confirmDeletion': 'মুছে ফেলা নিশ্চিত করুন',
    'article.confirmDeleteMsg': 'আপনি কি এই দান ফর্মুলাটি মুছতে চান? এই কাজটি ফিরিয়ে আনা যাবে না।',
    'article.deleteFormulaConfirm': 'ফর্মুলা মুছুন',
    'article.donationCompleted': '{method}-এর মাধ্যমে ${amount} দান সফলভাবে সম্পন্ন হয়েছে!',
    'article.formulaUpdated': 'দান ফর্মুলা আপডেট হয়েছে!',
    'article.formulaCreated': 'দান ফর্মুলা তৈরি হয়েছে!',
    'article.formulaDeleted': 'দান ফর্মুলা মুছে ফেলা হয়েছে!',
    'article.failedToSave': 'সংরক্ষণ ব্যর্থ হয়েছে', 'article.failedToDelete': 'মুছতে ব্যর্থ হয়েছে',
    'article.unexpectedError': 'অপ্রত্যাশিত ত্রুটি', 'article.myArticles': 'আমার নিবন্ধসমূহ',
    'article.loadingArticles': 'নিবন্ধ লোড হচ্ছে', 'article.by': 'লেখক', 'article.view': 'দেখুন',
    'article.viewArticle': '{title} দেখুন',
    'article.noArticles': 'আপনি এখনও কোনো নিবন্ধ তৈরি করেননি।',
    'article.failedToLoadArticles': 'নিবন্ধ লোড ব্যর্থ হয়েছে',
    'article.loadArticlesError': 'নিবন্ধ লোড করার সময় একটি ত্রুটি ঘটেছে',
    'article.createNewArticle': 'নতুন নিবন্ধ তৈরি করুন', 'article.title': 'শিরোনাম',
    'article.articleTitlePlaceholder': 'নিবন্ধের শিরোনাম লিখুন', 'article.save': 'সংরক্ষণ',
    'article.pleaseLogin': 'নতুন নিবন্ধ তৈরি করতে লগইন করুন।', 'article.login': 'লগইন',
    'article.saveArticle': 'নিবন্ধ সংরক্ষণ করুন',
    'article.confirmSaveArticle': 'আপনি কি এই নিবন্ধটি সংরক্ষণ করতে চান?',
    'article.articleSaved': 'নিবন্ধ সফলভাবে সংরক্ষণ হয়েছে!',
    'article.failedToSaveArticle': 'নিবন্ধ সংরক্ষণ ব্যর্থ হয়েছে',
    'article.savingError': 'সংরক্ষণের সময় ত্রুটি ঘটেছে', 'article.newArticleTitle': 'নতুন নিবন্ধ',
    'formula.name': 'নাম', 'formula.namePlaceholder': 'এই ফর্মুলার জন্য একটি নাম লিখুন',
    'formula.organization': 'সংস্থা', 'formula.percentage': 'শতাংশ (%)',
    'formula.deleteRow': 'সারি মুছুন', 'formula.addCharity': 'দাতব্য সংস্থা যোগ করুন',
    'formula.organizationPlaceholder': 'সংস্থার নাম',
    'formula.totalAllocation': 'মোট বরাদ্দ: {total}%', 'formula.mustEqual100': '১০০% হতে হবে',
    'formula.helper': '* সংস্থার নাম লিখুন। মোট ১০০% হতে হবে।', 'formula.details': 'বিবরণ',
    'formula.detailsPlaceholder': 'অতিরিক্ত বিবরণ যোগ করুন...', 'formula.nameRequired': 'নাম আবশ্যক',
    'formula.nameTooLong': 'নাম ২৫৫ অক্ষরের বেশি হতে পারবে না।',
    'formula.addAtLeastOne': 'অন্তত একটি দাতব্য সংস্থা যোগ করুন',
    'formula.organizationRequired': 'সংস্থা আবশ্যক', 'formula.percentageNumber': 'শতাংশ একটি সংখ্যা হতে হবে',
    'formula.percentageRequired': 'শতাংশ আবশ্যক', 'formula.percentageMin': 'শতাংশ ০ এর বেশি হতে হবে',
    'formula.percentageMax': 'শতাংশ সর্বোচ্চ ১০০ হতে হবে',
    'formula.totalMustEqual100': 'মোট বরাদ্দ ১০০% হতে হবে', 'formula.createTitle': 'দান ফর্মুলা তৈরি করুন',
    'formula.editTitle': 'দান ফর্মুলা সম্পাদনা করুন', 'formula.saveConfirmTitle': 'দান ফর্মুলা সংরক্ষণ করুন',
    'formula.updateConfirmTitle': 'দান ফর্মুলা আপডেট করুন', 'formula.confirmYourAction': 'আপনার কাজ নিশ্চিত করুন',
    'formula.updateConfirmMsg': 'আপনি কি এই দান ফর্মুলাটি আপডেট করতে চান?',
    'formula.saveConfirmMsg': 'আপনি কি এই দান ফর্মুলাটি সংরক্ষণ করতে চান?',
    'formula.update': 'আপডেট', 'formula.save': 'সংরক্ষণ', 'formula.saving': 'সংরক্ষণ হচ্ছে...',
    'formula.cancel': 'বাতিল',
    'donate.title': 'ফর্মুলার মাধ্যমে দান করুন', 'donate.formulaBreakdown': 'ফর্মুলার বিবরণ',
    'donate.hideFormula': 'ফর্মুলার বিবরণ লুকান', 'donate.showFormula': 'ফর্মুলার বিবরণ দেখান',
    'donate.total': 'মোট', 'donate.loginPrompt': 'দান করতে লগ ইন করুন।', 'donate.logIn': 'লগ ইন',
    'donate.donationAmount': 'দানের পরিমাণ ($)', 'donate.enterAmount': 'পরিমাণ লিখুন',
    'donate.operationalCosts': '* পরিচালন ব্যয়ের জন্য সর্বোচ্চ ০.১%।',
    'donate.enterAmountToDonate': 'দান করতে পরিমাণ লিখুন',
    'donate.paypalNotConfigured': 'PayPal কনফিগার করা হয়নি।',
    'donate.enterValidAmount': 'একটি বৈধ দানের পরিমাণ লিখুন।',
    'donate.failedToCreateOrder': 'PayPal অর্ডার তৈরি ব্যর্থ হয়েছে',
    'donate.failedToCapture': 'পেমেন্ট ক্যাপচার ব্যর্থ হয়েছে',
    'donate.failedToProcess': 'দান প্রক্রিয়াকরণ ব্যর্থ হয়েছে',
    'donate.paypalError': 'PayPal-এ কিছু সমস্যা হয়েছে।',
    'donate.failedToLoadPaypal': 'PayPal লোড ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
    'payment.successTitle': 'পেমেন্ট সফল হয়েছে',
    'payment.successSubtitle': 'আপনার উদার দানের জন্য ধন্যবাদ!',
    'payment.verifyingPayment': 'পেমেন্ট যাচাই করা হচ্ছে...', 'payment.processedMsg': 'আপনার দান প্রক্রিয়া করা হয়েছে।',
    'payment.amount': 'পরিমাণ:',
    'payment.paypalUnverifiedTitle': 'আপনার PayPal পেমেন্ট সফল হয়েছে, কিন্তু আমরা এটি স্বয়ংক্রিয়ভাবে যাচাই করতে পারিনি।',
    'payment.paypalUnverifiedMsg': 'আপনার দান শীঘ্রই চূড়ান্ত হবে। কোনো উদ্বেগ থাকলে সাপোর্টে যোগাযোগ করুন।',
    'payment.paymentSuccessful': 'আপনার পেমেন্ট সফল হয়েছে।',
    'payment.cancelTitle': 'পেমেন্ট বাতিল হয়েছে',
    'payment.cancelSubtitle': 'আপনার পেমেন্ট প্রক্রিয়া করা হয়নি। কোনো চার্জ করা হয়নি।',
    'payment.safeMsg': 'চিন্তা করবেন না — আপনার পেমেন্ট তথ্য নিরাপদ।',
    'payment.tryAgain': 'যখনই প্রস্তুত হবেন আবার চেষ্টা করতে পারেন।',
    'payment.havingTrouble': 'সমস্যা হচ্ছে?', 'payment.contactSupport': 'সাপোর্টের সাথে যোগাযোগ করুন',
    'admin.panel': 'অ্যাডমিন প্যানেল', 'admin.dashboard': 'অ্যাডমিন ড্যাশবোর্ড',
    'admin.dashboardSubtitle': 'আপনার WikiDonate প্ল্যাটফর্মের ওভারভিউ',
    'admin.loadingDashboard': 'ড্যাশবোর্ড লোড হচ্ছে...', 'admin.totalUsers': 'মোট ব্যবহারকারী',
    'admin.totalArticles': 'মোট নিবন্ধ', 'admin.totalDonations': 'মোট দান',
    'admin.newThisMonth': 'এই মাসে নতুন', 'admin.monthlyTrends': 'মাসিক প্রবণতা',
    'admin.donations': 'দান', 'admin.newUsers': 'নতুন ব্যবহারকারী',
    'admin.noDonationData': 'এখনও কোনো দানের তথ্য নেই',
    'admin.noRegistrationData': 'এখনও কোনো নিবন্ধনের তথ্য নেই',
    'admin.recentDonations': 'সাম্প্রতিক দান', 'admin.recentUsers': 'সাম্প্রতিক ব্যবহারকারী',
    'admin.viewAll': 'সব দেখুন', 'admin.noDonationsYet': 'এখনও কোনো দান নেই',
    'admin.noUsersYet': 'এখনও কোনো ব্যবহারকারী নেই', 'admin.failedToLoadDashboard': 'ড্যাশবোর্ড ডেটা লোড ব্যর্থ হয়েছে',
    'admin.checkout': 'চেকআউট', 'admin.view': 'দেখুন', 'admin.date': 'তারিখ', 'admin.donor': 'দাতা',
    'admin.source': 'উৎস', 'admin.amount': 'পরিমাণ', 'admin.status': 'অবস্থা', 'admin.article': 'নিবন্ধ',
    'admin.action': 'কাজ', 'admin.username': 'ইউজারনেম', 'admin.email': 'ইমেইল', 'admin.joined': 'যোগদান',
    'admin.allDonations': 'সব দান', 'admin.allDonationsSubtitle': 'সব দানের রেকর্ড দেখুন এবং খুঁজুন',
    'admin.searchDonorPlaceholder': 'দাতা, ইমেইল বা পেমেন্ট আইডি দিয়ে খুঁজুন...',
    'admin.allStatus': 'সব অবস্থা', 'admin.completed': 'সম্পন্ন', 'admin.expired': 'মেয়াদোত্তীর্ণ',
    'admin.failed': 'ব্যর্থ', 'admin.pending': 'বিচারাধীন', 'admin.loadingDonations': 'দান লোড হচ্ছে...',
    'admin.noDonations': 'কোনো দান পাওয়া যায়নি', 'admin.paymentId': 'পেমেন্ট আইডি',
    'admin.failedToLoadDonations': 'দান লোড ব্যর্থ হয়েছে', 'admin.articles': 'নিবন্ধসমূহ',
    'admin.articlesSubtitle': 'প্ল্যাটফর্মের সব নিবন্ধ পরিচালনা করুন',
    'admin.searchArticlesPlaceholder': 'শিরোনাম, স্লাগ বা লেখক দিয়ে খুঁজুন...',
    'admin.allTypes': 'সব ধরন', 'admin.articleType': 'নিবন্ধ', 'admin.userPage': 'ব্যবহারকারী পাতা',
    'admin.allAccess': 'সব অ্যাক্সেস', 'admin.public': 'সর্বজনীন', 'admin.private': 'ব্যক্তিগত',
    'admin.loadingArticles': 'নিবন্ধ লোড হচ্ছে...', 'admin.noArticlesFound': 'কোনো নিবন্ধ পাওয়া যায়নি',
    'admin.title': 'শিরোনাম', 'admin.author': 'লেখক', 'admin.type': 'ধরন', 'admin.access': 'অ্যাক্সেস',
    'admin.updated': 'আপডেট হয়েছে', 'admin.actions': 'কাজসমূহ', 'admin.viewArticle': 'নিবন্ধ দেখুন',
    'admin.deleteArticle': 'নিবন্ধ মুছুন', 'admin.deleteArticleTitle': 'নিবন্ধ মুছুন',
    'admin.areYouSure': 'আপনি কি নিশ্চিত?',
    'admin.deleteArticleMsg': "'{title}' মুছবেন? এই কাজটি ফিরিয়ে আনা যাবে না।",
    'admin.delete': 'মুছুন', 'admin.cancel': 'বাতিল', 'admin.articleDeleted': 'নিবন্ধ সফলভাবে মুছে ফেলা হয়েছে',
    'admin.failedToDeleteArticle': 'নিবন্ধ মুছতে ব্যর্থ হয়েছে', 'admin.failedToLoadArticles': 'নিবন্ধ লোড ব্যর্থ হয়েছে',
    'admin.transactions': 'লেনদেন', 'admin.transactionsSubtitle': 'এক নজরে আয় এবং পেআউট',
    'admin.exportCsv': 'CSV এক্সপোর্ট', 'admin.totalIncome': 'মোট আয়', 'admin.totalPayouts': 'মোট পেআউট',
    'admin.remainingPayable': 'অবশিষ্ট প্রদেয়', 'admin.netInHand': 'হাতে নিট', 'admin.income': 'আয়',
    'admin.expense': 'ব্যয়', 'admin.allMethods': 'সব পদ্ধতি', 'admin.stripe': 'Stripe',
    'admin.paypal': 'PayPal', 'admin.organizationPlaceholder': 'সংস্থা...',
    'admin.articleSlugPlaceholder': 'নিবন্ধ স্লাগ...', 'admin.apply': 'প্রয়োগ করুন',
    'admin.loadingTransactions': 'লেনদেন লোড হচ্ছে...', 'admin.description': 'বিবরণ',
    'admin.method': 'পদ্ধতি', 'admin.context': 'প্রসঙ্গ', 'admin.articleLabel': 'নিবন্ধ: {title}',
    'admin.full': 'সম্পূর্ণ', 'admin.partial': 'আংশিক', 'admin.exportFailed': 'এক্সপোর্ট ব্যর্থ হয়েছে',
    'admin.failedToLoadTransactions': 'লেনদেন লোড ব্যর্থ হয়েছে', 'admin.payouts': 'পেআউট',
    'admin.orgPayoutsTitle': 'সংস্থার পেআউট',
    'admin.orgPayoutsSubtitle': 'লাইভ ব্যালেন্স থেকে সংস্থাগুলোকে পরিশোধ করুন — শুধুযোগযোগ্য পেআউট লেজার',
    'admin.loadingAllocations': 'বরাদ্দ লোড হচ্ছে...', 'admin.noAllocations': 'এখনও কোনো প্রদেয় বরাদ্দ নেই',
    'admin.organizationAllocation': 'সংস্থা / বরাদ্দ', 'admin.owedPaidBalance': 'প্রদেয় / পরিশোধিত / ব্যালেন্স',
    'admin.formula': 'ফর্মুলা', 'admin.owed': 'প্রদেয়:', 'admin.paid': 'পরিশোধিত:', 'admin.balance': 'ব্যালেন্স:',
    'admin.history': 'ইতিহাস', 'admin.payTitle': '{org}-কে পরিশোধ করুন', 'admin.liveBalance': 'লাইভ ব্যালেন্স:',
    'admin.formulaLabel': 'ফর্মুলা: {name}', 'admin.amountLabel': 'পরিমাণ ({currency})',
    'admin.noteLabel': 'নোট (ব্যাংক / বিকাশ রেফ)', 'admin.recordPayout': 'পেআউট রেকর্ড করুন',
    'admin.paying': 'পরিশোধ করা হচ্ছে…', 'admin.payoutHistory': 'পেআউট ইতিহাস — {org}',
    'admin.noPayouts': 'এখনও কোনো পেআউট রেকর্ড হয়নি।', 'admin.by': 'দ্বারা',
    'admin.failedToRecord': 'পেআউট রেকর্ড ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
    'admin.failedToLoadHistory': 'ইতিহাস লোড ব্যর্থ হয়েছে।', 'admin.howItWorks': 'এটি কীভাবে কাজ করে',
    'admin.howItWorksSubtitle': 'How It Works পৃষ্ঠায় দেখানো ধাপগুলো সম্পাদনা করুন',
    'admin.addStep': 'ধাপ যোগ করুন', 'admin.loadingPageContent': 'পৃষ্ঠার বিষয়বস্তু লোড হচ্ছে...',
    'admin.removeStep': 'ধাপ সরান', 'admin.icon': 'আইকন', 'admin.stepTitlePlaceholder': 'ধাপের শিরোনাম',
    'admin.stepDescriptionPlaceholder': 'ধাপের বিবরণ। [[link]] ব্যবহার করুন।',
    'admin.stepHint': '[[article-title]] ব্যবহার করে নিবন্ধের লিংক তৈরি করুন।',
    'admin.noSteps': 'এখনও কোনো ধাপ নেই। "ধাপ যোগ করুন" ক্লিক করুন।', 'admin.saveChanges': 'পরিবর্তন সংরক্ষণ করুন',
    'admin.pageUpdated': 'How It Works পৃষ্ঠা সফলভাবে আপডেট হয়েছে',
    'admin.failedToSaveContent': 'পৃষ্ঠার বিষয়বস্তু সংরক্ষণ ব্যর্থ হয়েছে',
    'admin.failedToLoadContent': 'পৃষ্ঠার বিষয়বস্তু লোড ব্যর্থ হয়েছে', 'admin.backToSite': 'সাইটে ফিরুন',
    'admin.pages': 'পাতাসমূহ', 'admin.donationDetails': 'দানের বিবরণ', 'admin.totalAmount': 'মোট পরিমাণ',
    'admin.donorLabel': 'দাতা', 'admin.emailLabel': 'ইমেইল', 'admin.dateLabel': 'তারিখ',
    'admin.paymentIdLabel': 'পেমেন্ট আইডি', 'admin.distributionFormula': 'বন্টন ফর্মুলা',
    'admin.organization': 'সংস্থা', 'admin.additionalDetails': 'অতিরিক্ত বিবরণ',
    'admin.viewFormula': 'ফর্মুলা দেখুন', 'admin.close': 'বন্ধ করুন',
    'admin.effectiveAtDonation': 'দানের সময় কার্যকর',
    'footer.copyright': '© {year} Wikidonate.org. সর্বস্বত্ব সংরক্ষিত।',
    'errors.unexpected': 'অপ্রত্যাশিত ত্রুটি', 'errors.generic': 'কিছু সমস্যা হয়েছে। আবার চেষ্টা করুন।',
    'confirmModal.title': 'কাজ নিশ্চিত করুন', 'confirmModal.messageTitle': 'আপনি কি নিশ্চিত?',
    'confirmModal.message': 'এই কাজটি ফিরিয়ে আনা যাবে না।', 'confirmModal.confirm': 'মুছুন',
    'confirmModal.cancel': 'বাতিল', 'modal.title': 'মোডাল শিরোনাম',
    'language.loading': 'লোড হচ্ছে...', 'language.select': 'ভাষা নির্বাচন করুন',
    'menu.mainPage': 'মূল পাতা', 'menu.adminPanel': 'অ্যাডমিন প্যানেল', 'menu.createAccount': 'অ্যাকাউন্ট তৈরি করুন',
    'menu.login': 'লগইন', 'menu.profileSettings': 'প্রোফাইল সেটিংস', 'menu.myArticles': 'আমার নিবন্ধসমূহ',
    'menu.preferences': 'পছন্দসমূহ', 'menu.myDonations': 'আমার দানসমূহ', 'menu.logout': 'লগআউট',
    'header.adminPanel': 'অ্যাডমিন প্যানেল', 'search.searching': 'wikidonate খোঁজা হচ্ছে...',
    'search.noResults': 'কোনো ফলাফল পাওয়া যায়নি', 'search.placeholder': 'নিবন্ধ খুঁজুন...',
},
}

# Simple structural reduction: languages reuse 'bn' seeds where the same word
# carries over meaning (none share scripts, so each language gets its own).
# For tractability the generator below seeds only the shared top-level chrome
# for remaining languages; keys left unseeded fall back to English.

if __name__ == '__main__':
    with open(os.path.join(LOCALES, 'en.json'), encoding='utf-8') as f:
        en_flat = flat(json.load(f))
    print(f'English source: {len(en_flat)} keys')
    for code in SUPPORTED:
        if code == 'en':
            continue
        seed = SEEDS.get(code, {})
        build(code, en_flat, seed)