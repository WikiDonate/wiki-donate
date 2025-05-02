/* eslint-disable @typescript-eslint/no-explicit-any */
import { defineEventHandler, readBody } from 'h3'
import type { Browser, Page } from 'playwright'
import { chromium } from 'playwright'

async function clickElement(page: Page, selector: string) {
    await page.waitForSelector(selector)
    await page.click(selector)
    console.log(`Clicked element with selector: "${selector}"`)
}

async function fillInputField(page: Page, selector: string, value: string) {
    await page.waitForSelector(selector)
    await page.fill(selector, value)
    console.log(`Filled input "${selector}" with value: "${value}"`)
}

export default defineEventHandler(async (event) => {
    const { amount } = await readBody(event)
    const donationAmount = parseFloat(amount as string) * 0.5

    if (isNaN(donationAmount) || donationAmount <= 0) {
        return { success: false, error: 'Invalid donation amount.' }
    }

    let browser: Browser | null = null
    try {
        browser = await chromium.launch({ headless: false }) // Keep headless: false for observation
        const page = await browser.newPage()
        await page.goto(
            'https://give.doctorswithoutborders.org/campaign/675296/donate'
        )

        // --- Donation Form Interaction ---
        const oneTimeButtonSelector =
            'button[data-id="cp-donation-form-one-time"]'
        const otherAmountSelector = '#other-donation-amount'
        const continueButtonSelector = 'a[data-id="cp-donation-form-donate"]'

        // 1. Click the "One-time" button
        await clickElement(page, oneTimeButtonSelector)

        // 2. Fill the "Other" donation amount
        await fillInputField(
            page,
            otherAmountSelector,
            donationAmount.toFixed(2).toString()
        )

        // 3. Click the "Continue" button on the donation form
        await clickElement(page, continueButtonSelector)

        // --- Checkout Process ---
        const cardRadioButtonSelector =
            '#radio-button-CC[data-id="paymethod-card"]'
        const continueToCardDetailsButtonSelector =
            'button[data-testid="paymethod-continue"][data-id="paymethod-continue"]'

        // 4. Select the "Card" payment method on the checkout page
        await page.waitForURL(
            'https://give.doctorswithoutborders.org/checkout*'
        )
        // Wait for navigation to the checkout page
        await clickElement(page, cardRadioButtonSelector)

        // 5. Click the "Continue to card details" button on the checkout page
        await clickElement(page, continueToCardDetailsButtonSelector)

        // --- Next steps: Fill in card details ---
        // You will need to identify selectors for card number, expiry date, CVV, etc.
        // **Handling payment information requires extreme caution and should ideally be avoided.**

        // await browser.close()
        return {
            success: true,
            message: `Successfully navigated through donation form, selected card payment, and proceeded to card details.`,
        }
    } catch (error: any) {
        console.error('Error during automation:', error)
        if (browser) {
            await browser.close()
        }
        return { success: false, error: error.message }
    }
})
