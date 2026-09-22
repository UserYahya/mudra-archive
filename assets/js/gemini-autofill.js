/**
 * assets/js/gemini-autofill.js - Gemini AI Numismatic Form Autofill for Mudra Archive
 * Automatically discovers active Gemini Vision models (gemini-2.5-flash, gemini-2.0-flash, gemini-1.5-flash, etc.)
 * Analyzes coin photos and populates catalog fields, keeping Acquisition Date & Seller untouched.
 */

(function () {
    const STORAGE_KEY = 'mudra_gemini_api_key';
    const MODEL_KEY = 'mudra_gemini_model_name';

    function getApiKey() {
        return localStorage.getItem(STORAGE_KEY) || '';
    }

    function setApiKey(key) {
        localStorage.setItem(STORAGE_KEY, key.trim());
    }

    function getPreferredModel() {
        return localStorage.getItem(MODEL_KEY) || 'auto';
    }

    function setPreferredModel(model) {
        localStorage.setItem(MODEL_KEY, model.trim());
    }

    // Discover available models for this API key from Google AI API
    async function fetchAvailableVisionModels(apiKey) {
        try {
            const resp = await fetch(`https://generativelanguage.googleapis.com/v1beta/models?key=${apiKey}`);
            if (!resp.ok) return [];
            const data = await resp.json();
            if (!data.models || !Array.isArray(data.models)) return [];

            // Filter models supporting generateContent
            const valid = data.models
                .filter(m => m.supportedGenerationMethods && m.supportedGenerationMethods.includes('generateContent'))
                .map(m => m.name.replace(/^models\//, ''))
                .filter(name => !name.includes('embedding') && !name.includes('aqa'));

            // Sort: prioritize flash models
            valid.sort((a, b) => {
                const aFlash = a.includes('flash') ? 1 : 0;
                const bFlash = b.includes('flash') ? 1 : 0;
                return bFlash - aFlash;
            });

            return valid;
        } catch (e) {
            console.log('Model discovery error:', e);
            return [];
        }
    }

    // Modal / API Key prompt UI
    function showApiKeyModal(callback) {
        let modalEl = document.getElementById('geminiApiKeyModal');
        if (!modalEl) {
            const modalHtml = `
                <div class="modal fade" id="geminiApiKeyModal" tabindex="-1" aria-labelledby="geminiApiKeyModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title d-flex align-items-center gap-2" id="geminiApiKeyModalLabel">
                                    <span class="material-symbols-outlined">auto_awesome</span>
                                    <span>Gemini AI Configuration</span>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <p class="text-muted small mb-3">
                                    Mudra Archive uses Google Gemini AI to analyze coin images, inscriptions, and iconography to autofill numismatic catalog fields automatically.
                                </p>
                                <div class="mb-3">
                                    <label class="form-label font-label-archival fw-bold">Gemini API Key</label>
                                    <input type="password" id="geminiApiKeyInput" class="form-control font-monospace" placeholder="AIzaSy..." value="${getApiKey()}" />
                                    <div class="form-text mt-2">
                                        Get a 100% free key from <a href="https://aistudio.google.com/app/apikey" target="_blank" class="fw-bold text-decoration-underline">Google AI Studio</a>.
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label font-label-archival fw-bold">Gemini Vision Model</label>
                                    <select id="geminiModelSelect" class="form-select font-monospace text-dark">
                                        <option value="auto">Auto-detect Active Model (Recommended)</option>
                                        <option value="gemini-2.0-flash">gemini-2.0-flash (Latest Fast Vision)</option>
                                        <option value="gemini-2.5-flash">gemini-2.5-flash</option>
                                        <option value="gemini-1.5-flash">gemini-1.5-flash</option>
                                        <option value="gemini-1.5-flash-latest">gemini-1.5-flash-latest</option>
                                        <option value="gemini-1.5-pro">gemini-1.5-pro (High Detail)</option>
                                    </select>
                                </div>
                                <div class="alert alert-info small py-2 d-flex align-items-center gap-2 mb-0">
                                    <span class="material-symbols-outlined fs-5">lock</span>
                                    <span>Your key is saved locally in your browser's private storage.</span>
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary-archival" id="saveApiKeyBtn">Save Settings</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            modalEl = document.getElementById('geminiApiKeyModal');
        }

        const input = document.getElementById('geminiApiKeyInput');
        input.value = getApiKey();
        const modelSelect = document.getElementById('geminiModelSelect');
        if (modelSelect) modelSelect.value = getPreferredModel();

        const saveBtn = document.getElementById('saveApiKeyBtn');
        const bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();

        saveBtn.onclick = function () {
            const key = input.value.trim();
            if (!key) {
                input.classList.add('is-invalid');
                return;
            }
            input.classList.remove('is-invalid');
            setApiKey(key);
            if (modelSelect) setPreferredModel(modelSelect.value);
            bsModal.hide();
            if (typeof callback === 'function') {
                callback(key);
            }
        };
    }

    // Check if both images are ready
    function checkImagesReady() {
        const obv = window.coinImageData && window.coinImageData.obverse;
        const rev = window.coinImageData && window.coinImageData.reverse;
        const aiCard = document.getElementById('geminiAiAutofillCard');
        const aiBtn = document.getElementById('btnGeminiAutofill');

        if (!aiCard || !aiBtn) return;

        if (obv && rev) {
            aiCard.classList.remove('d-none');
            aiBtn.disabled = false;
        }
    }

    // Call Gemini Vision API
    async function triggerGeminiAutofill() {
        let apiKey = getApiKey();
        if (!apiKey) {
            showApiKeyModal(function (newKey) {
                if (newKey) runGeminiVisionAnalysis(newKey);
            });
            return;
        }

        runGeminiVisionAnalysis(apiKey);
    }

    async function runGeminiVisionAnalysis(apiKey) {
        const obv = window.coinImageData && window.coinImageData.obverse;
        const rev = window.coinImageData && window.coinImageData.reverse;

        if (!obv || !rev) {
            alert('Please select both Obverse (Front) and Reverse (Back) coin images first.');
            return;
        }

        const aiBtn = document.getElementById('btnGeminiAutofill');
        const statusBox = document.getElementById('geminiAiStatus');
        
        if (aiBtn) {
            aiBtn.disabled = true;
            aiBtn.innerHTML = `
                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                Analyzing coin iconography & inscriptions with Gemini AI...
            `;
        }

        if (statusBox) {
            statusBox.className = 'alert alert-warning d-flex align-items-center gap-2 mt-3 mb-0';
            statusBox.innerHTML = `
                <span class="spinner-border spinner-border-sm text-warning" role="status"></span>
                <span>AI Vision in progress: Examining coin details, lettering, and catalog references...</span>
            `;
            statusBox.classList.remove('d-none');
        }

        const promptText = `You are a world-class professional numismatist and coin cataloging expert.
Analyze these two coin images carefully:
- Image 1 is the OBVERSE (front side).
- Image 2 is the REVERSE (back side).

Identify the coin with high accuracy. Return ONLY a single valid JSON object with the following schema and fields:
{
  "country": "Exact issuing country or authority name (e.g. Bangladesh, United States, United Kingdom, Greece, India, Roman Empire, Germany, France, Japan, etc.)",
  "currency_name": "Name of the currency (e.g. Taka, Dollar, Pound, Sovereign, Rupee, Euro, Drachma, Denarius, Kopecks)",
  "denomination": "Full denomination name (e.g. 10 Taka, Morgan Dollar, 1 Sovereign, 1 Rupee, 50 Cents, Tetradrachm, 20 Kopecks)",
  "year": "Mint year or era shown on coin (e.g. 1921, 2011, 1887, c. 440 BC)",
  "mint_mark": "Mint mark if visible or known for this specimen (e.g. Dhaka, S, D, O, M, AΘE, ROME, or empty string)",
  "ruler_or_series": "Ruler, monarch, commemorated figure or series name (e.g. Queen Victoria, Bangabandhu, ICC Cricket World Cup, Classical Owl, Emperor Augustus, Liberty Head)",
  "material": "Metal or composition (e.g. Silver, Gold, Bronze, Copper, Copper-Nickel, Brass, Bi-Metallic)",
  "weight_grams": 0.0,
  "diameter_mm": 0.0,
  "condition_grade": "Estimated physical condition / grade based on visible wear (e.g. UNC, MS-63, AU-55, XF-40, VF-30, Fine, Good)",
  "notes": "Comprehensive, authoritative numismatic catalog description including obverse and reverse iconography, historical significance, minting context, and edge characteristics."
}

CRITICAL RULES:
1. Do NOT include "acquisition_date", "source_or_seller", or "acquisition_price".
2. weight_grams and diameter_mm should be numbers (standard catalog specs) or null if unknown.
3. Return pure JSON without markdown fences or extra text.`;

        const requestPayload = {
            contents: [
                {
                    role: "user",
                    parts: [
                        {
                            inlineData: {
                                mimeType: obv.mimeType || "image/jpeg",
                                data: obv.base64
                            }
                        },
                        {
                            inlineData: {
                                mimeType: rev.mimeType || "image/jpeg",
                                data: rev.base64
                            }
                        },
                        {
                            text: promptText
                        }
                    ]
                }
            ],
            generationConfig: {
                temperature: 0.1,
                responseMimeType: "application/json"
            }
        };

        // Determine list of candidate models
        let candidateModels = [];
        const preferred = getPreferredModel();

        if (preferred && preferred !== 'auto') {
            candidateModels.push(preferred);
        }

        // Try dynamically discovering available models from user's key
        const discovered = await fetchAvailableVisionModels(apiKey);
        if (discovered.length > 0) {
            discovered.forEach(m => {
                if (!candidateModels.includes(m)) candidateModels.push(m);
            });
        }

        // Standard robust fallback model list
        const defaultFallbacks = [
            'gemini-2.0-flash',
            'gemini-2.0-flash-exp',
            'gemini-1.5-flash',
            'gemini-1.5-flash-latest',
            'gemini-2.5-flash',
            'gemini-1.5-pro',
            'gemini-1.5-flash-8b'
        ];
        defaultFallbacks.forEach(m => {
            if (!candidateModels.includes(m)) candidateModels.push(m);
        });

        let success = false;
        let lastError = null;
        let usedModel = '';

        for (const model of candidateModels) {
            try {
                const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${apiKey}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload)
                });

                if (!response.ok) {
                    const errData = await response.json().catch(() => ({}));
                    const errMsg = errData.error ? errData.error.message : `HTTP ${response.status}`;
                    lastError = `${model}: ${errMsg}`;
                    if (response.status === 400 && errMsg.includes('API key not valid')) {
                        break;
                    }
                    continue; // Try next model candidate
                }

                const result = await response.json();
                const candidates = result.candidates;
                if (!candidates || !candidates[0] || !candidates[0].content || !candidates[0].content.parts) {
                    throw new Error('No candidate content returned by Gemini.');
                }

                let rawText = candidates[0].content.parts[0].text.trim();
                rawText = rawText.replace(/^```json\s*/i, '').replace(/\s*```$/i, '').trim();

                const coinData = JSON.parse(rawText);
                populateFormWithCoinData(coinData);
                usedModel = model;
                success = true;
                break;
            } catch (err) {
                lastError = `${model}: ${err.message || err}`;
            }
        }

        if (aiBtn) {
            aiBtn.disabled = false;
            aiBtn.innerHTML = `
                <span class="material-symbols-outlined fs-5">auto_awesome</span>
                <span>Autofill with Gemini AI</span>
            `;
        }

        if (!success) {
            if (statusBox) {
                statusBox.className = 'alert alert-danger d-flex align-items-center justify-content-between gap-2 mt-3 mb-0';
                statusBox.innerHTML = `
                    <div class="d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined fs-5">error</span>
                        <span>AI Autofill Failed (${escapeHtml(lastError || 'No vision model available')}). Please check your API key or model settings.</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="reconfigApiKeyBtn">Update Key / Model</button>
                `;
                const reconfigBtn = document.getElementById('reconfigApiKeyBtn');
                if (reconfigBtn) {
                    reconfigBtn.onclick = () => showApiKeyModal(() => runGeminiVisionAnalysis(getApiKey()));
                }
            }
        } else {
            if (statusBox) {
                statusBox.className = 'alert alert-success d-flex align-items-center justify-content-between gap-2 mt-3 mb-0';
                statusBox.innerHTML = `
                    <div class="d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined fs-5">check_circle</span>
                        <span>✨ <strong>Success!</strong> Numismatic catalog details populated via <code>${escapeHtml(usedModel)}</code>. Please review and enter <em>Acquisition Date</em> and <em>Source/Seller</em>.</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="this.parentElement.classList.add('d-none')">Dismiss</button>
                `;
            }
        }
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function populateFormWithCoinData(data) {
        const form = document.getElementById('addCoinForm') || document.getElementById('editCoinForm');
        if (!form) return;

        function setField(name, val) {
            if (val === undefined || val === null) return;
            const input = form.querySelector(`[name="${name}"]`);
            if (!input) return;

            if (input.tagName === 'SELECT') {
                let found = false;
                const target = String(val).toLowerCase().trim();
                for (let i = 0; i < input.options.length; i++) {
                    const optText = input.options[i].text.toLowerCase().trim();
                    const optVal = input.options[i].value.toLowerCase().trim();
                    if (optText === target || optVal === target || optText.includes(target) || target.includes(optText)) {
                        input.selectedIndex = i;
                        found = true;
                        break;
                    }
                }
                if (!found && val) {
                    const newOpt = new Option(val, val, true, true);
                    input.add(newOpt);
                }
            } else {
                input.value = val;
            }

            // Flash visual indicator on updated fields
            input.classList.add('border-primary', 'bg-light');
            setTimeout(() => {
                input.classList.remove('border-primary', 'bg-light');
            }, 2500);
        }

        // Populate numismatic fields
        setField('country', data.country);
        setField('currency_name', data.currency_name);
        setField('denomination', data.denomination);
        setField('year', data.year);
        setField('mint_mark', data.mint_mark);
        setField('ruler_or_series', data.ruler_or_series);
        setField('material', data.material);
        setField('weight_grams', data.weight_grams);
        setField('diameter_mm', data.diameter_mm);
        setField('condition_grade', data.condition_grade);
        setField('notes', data.notes);

        // Strict: Acquisition Date and Source/Seller remain untouched
    }

    // Init listeners
    document.addEventListener('DOMContentLoaded', function () {
        window.addEventListener('coinImagesUpdated', checkImagesReady);

        const aiBtn = document.getElementById('btnGeminiAutofill');
        if (aiBtn) {
            aiBtn.addEventListener('click', triggerGeminiAutofill);
        }

        const settingsKeyBtn = document.getElementById('btnConfigureApiKey');
        if (settingsKeyBtn) {
            settingsKeyBtn.addEventListener('click', () => showApiKeyModal());
        }
    });

    window.GeminiAutofill = {
        showApiKeyModal,
        triggerGeminiAutofill,
        getApiKey,
        setApiKey
    };
})();
