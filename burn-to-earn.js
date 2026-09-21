// /public_html/wp-content/themes/astra/js/burn-to-earn.js
if (window.isDebug) console.log('burn-to-earn.js loaded');

// Background image URLs
const backgroundImages = [
    'https://imcollectibles.io/wp-content/uploads/2025/08/1-1.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/2-1.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/3-1.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/4.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/5.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/6.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/7.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/8.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/9.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/10.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/11.png',
    'https://imcollectibles.io/wp-content/uploads/2025/08/12.png'
];

// Disable Dropzone auto-discovery to prevent conflicts
Dropzone.autoDiscover = false;

document.addEventListener('DOMContentLoaded', () => {
    // Add cache-busting URL parameter
    if (window.location.pathname.includes('/burn-to-earn/')) {
        window.history.replaceState({}, '', `${window.location.pathname}?t=${Date.now()}`);
    }

    const logError = async (message) => {
        if (window.isDebug) console.error(message);
        if (window.xamanNotifications && window.xamanNotifications.ajax_url) {
            try {
                await fetch(window.xamanNotifications.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'log_js_error', message })
                });
            } catch (error) {
                if (window.isDebug) console.error('Failed to log error to server:', error);
            }
        }
    };

    // Validate xrpl_account
    const cookieAccount = document.cookie.split('; ').find(row => row.startsWith('xrpl_account=')) ? document.cookie.split('; ').find(row => row.startsWith('xrpl_account=')).split('=')[1] : '';
    if (!cookieAccount || !cookieAccount.match(/^r[0-9a-zA-Z]{25,34}$/)) {
        document.querySelector('.burn-to-earn-container').innerHTML = `
            <p>Please log in with Xaman to access Burn-2-Earn.</p>
            ${document.querySelector('.xaman-login-container') ? document.querySelector('.xaman-login-container').outerHTML : ''}
        `;
        logError('Invalid or no xrpl_account cookie found on burn-to-earn page');
        return;
    }
    if (cookieAccount !== window.xrpl_account) {
        logError(`Account mismatch: cookie=${cookieAccount}, window.xrpl_account=${window.xrpl_account}`);
        window.xrpl_account = cookieAccount;
        window.xrplMarketplace.user_account = cookieAccount;
        window.xamanNotifications.externalUserId = cookieAccount;
    }

    const container = document.querySelector('.burn-to-earn-container');
    const nftGrid = document.querySelector('#nft-grid');
    const burnRow = document.querySelector('#burn-row');
    const progressBar = document.querySelector('#progress-bar');
    const freqCounter = document.querySelector('#freq-counter');
    const ledgerCounter = document.querySelector('#ledger-counter');
    const designBtn = document.querySelector('#design-protector-btn');
    const filterSelect = document.querySelector('#nft-filter');
    const historyToggle = document.querySelector('#history-toggle');
    const submissionToggle = document.querySelector('#submission-toggle');
    const designForm = document.querySelector('#design-form');
    const dropzoneForm = document.querySelector('#dropzone-upload');
    const submitButton = document.querySelector('#design-form .submit-form');
    let selectedNFT = null;  // Changed to single NFT (no array)
    let pendingBurnedCount = 0;
    let currentNonce = window.xrplMarketplace ? window.xrplMarketplace.nonce : '';

    // Clear stale session storage
    const staleTransientKey = 'xaman_nft_' + btoa('rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga').slice(0, 16);
    sessionStorage.removeItem(staleTransientKey);

    // Refresh nonce
    const fetchNonce = async (retries = 3) => {
    while (retries > 0) {
        try {
            const response = await fetch('/wp-admin/admin-ajax.php?action=get_nonce', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
            const data = await response.json();
            currentNonce = data.nonce || currentNonce;
            if (window.xrplMarketplace) window.xrplMarketplace.nonce = currentNonce;
            return currentNonce;
        } catch (error) {
            logError(`Nonce fetch failed (attempt ${4 - retries}): ${error.message}`);
            retries--;
            if (retries > 0) await new Promise(resolve => setTimeout(resolve, 1000 * (4 - retries)));
        }
    }
    return currentNonce;  // Fallback to existing
};

    // Initialize Dropzone
    if (dropzoneForm && window.xamanNotifications && window.xamanNotifications.ajax_url && submitButton) {
        try {
            const myDropzone = new Dropzone('#dropzone-upload', {
    url: '/burn-to-earn.php',
    maxFiles: 1,
    maxFilesize: 5,
    acceptedFiles: 'image/jpeg,image/png',
    paramName: 'design_images',
    addRemoveLinks: false, // Disable default remove link to avoid duplicate
    clickable: '.dropzone-upload-btn',
    dictDefaultMessage: 'Drag & Drop or Click to Upload (Optional, 1 image, 5MB max)',
    timeout: 60000,
    autoProcessQueue: false,
    previewTemplate: `
    <div class="dz-preview dz-file-preview" style="display: flex; align-items: center; background: #1a1a1a; padding: 10px; border: 1px solid #d6ba66; border-radius: 5px; margin: 10px auto; max-width: 300px;">
        <div class="dz-image" style="flex: 0 0 auto; width: 75px; height: 75px; overflow: hidden; transform: translateY(-15px); z-index: 1;">
            <img data-dz-thumbnail style="max-width: 100%; max-height: 100%; height: auto; object-fit: contain;" />
        </div>
        <div class="dz-details" style="flex: 1; margin-left: 10px; min-width: 0;">
            <div class="dz-filename" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><span data-dz-name></span></div>
            <div class="dz-size" data-dz-size></div>
        </div>
        <a class="dz-remove" href="javascript:undefined;" data-dz-remove style="color: #ff4500; font-size: 12px; text-decoration: none; cursor: pointer; margin-left: 10px; flex: 0 0 auto; z-index: 10; padding: 5px; line-height: normal;">Remove</a>
        <div class="dz-progress" style="display: none;"><span class="dz-upload" data-dz-uploadprogress></span></div>
        <div class="dz-error-message" style="color: #ff4500; font-size: 14px; margin-top: 5px; text-align: center; width: 100%;"><span data-dz-errormessage></span></div>
        <div class="dz-success-mark" style="display: none;"><span>✔</span></div>
        <div class="dz-error-mark" style="display: none;"><span>✘</span></div>
    </div>
`,
    init: function () {
        this.on('dragenter', () => {
            dropzoneForm.classList.add('dragover');
        });
        this.on('dragleave', () => {
            dropzoneForm.classList.remove('dragover');
        });
        this.on('drop', () => {
            dropzoneForm.classList.remove('dragover');
        });
        this.on('sending', async (file, xhr, formData) => {
            formData.append('action', 'submit_design');
            formData.append('_wpnonce', currentNonce);
            formData.append('xrpl_account', window.xrpl_account);
            formData.append('transient_key', this.transient_key);
            if (window.isDebug) console.log('Form data being sent (file upload):', {
                _wpnonce: currentNonce,
                action: 'submit_design',
                xrpl_account: window.xrpl_account,
                transient_key: this.transient_key
            });
        });
        this.on('error', (file, errorMessage, xhr) => {
            hideSubmissionLoadingOverlay();
            const message = typeof errorMessage === 'object' && errorMessage.error ? errorMessage.error : (errorMessage || 'Unknown upload error');
            if (window.isDebug) console.log('Upload error:', {
                message,
                status: xhr ? xhr.status : 'undefined',
                headers: xhr ? xhr.getAllResponseHeaders() : 'undefined',
                response: errorMessage
            });
            logError(`Dropzone error: ${message}, Status: ${xhr ? xhr.status : 'undefined'}, Headers: ${xhr ? xhr.getAllResponseHeaders() : 'undefined'}`);
            document.querySelector('#dropzone-upload .error-message').style.display = 'block';
            document.querySelector('#dropzone-upload .error-message').textContent = `Upload error: ${message}`;
            this.removeFile(file);
            document.querySelector('.dropzone-upload-btn').style.display = 'inline-block';
        });
        this.on('success', (file, response) => {
            hideSubmissionLoadingOverlay();
            if (window.isDebug) console.log('Upload success:', response);
            if (response.success) {
                confetti({ particleCount: 100, spread: 70 });
                designForm.classList.remove('active');
                unlockScroll();
                this.removeAllFiles();
                document.querySelector('#dropzone-upload .error-message').style.display = 'none';
                document.querySelector('.dropzone-upload-btn').style.display = 'inline-block';
                fetchBurnedCounts().then(updateCounters);
                fetchHistory('submissions', document.querySelector('#submission-history-table tbody'));
            } else {
                const message = response.error || 'Upload failed';
                if (window.isDebug) console.log('Upload failed:', response);
                logError(`Dropzone success response error: ${message}`);
                document.querySelector('#dropzone-upload .error-message').style.display = 'block';
                document.querySelector('#dropzone-upload .error-message').textContent = message;
                this.removeFile(file);
                document.querySelector('.dropzone-upload-btn').style.display = 'inline-block';
            }
        });
        this.on('addedfile', (file) => {
            document.querySelector('#dropzone-upload .error-message').style.display = 'none';
            if (file.size > 5 * 1024 * 1024) {
                this.removeFile(file);
                document.querySelector('#dropzone-upload .error-message').style.display = 'block';
                document.querySelector('#dropzone-upload .error-message').textContent = `File ${file.name} exceeds 5MB limit.`;
                document.querySelector('.dropzone-upload-btn').style.display = 'inline-block';
            } else {
                document.querySelector('.dropzone-upload-btn').style.display = 'none';
            }
        });
        this.on('removedfile', (file) => {
            if (this.files.length === 0) {
                document.querySelector('.dropzone-upload-btn').style.display = 'inline-block';
                document.querySelector('#dropzone-upload .error-message').style.display = 'none';
            }
        });
        this.on('maxfilesexceeded', (file) => {
            this.removeFile(file);
            document.querySelector('#dropzone-upload .error-message').style.display = 'block';
            document.querySelector('#dropzone-upload .error-message').textContent = 'Maximum 1 file allowed.';
            document.querySelector('.dropzone-upload-btn').style.display = 'inline-block';
        });
    }
});

            // Handle form submission
            submitButton.addEventListener('click', async (e) => {
                e.preventDefault();
                const email = document.querySelector('#design-form [name="email"]').value.trim();
                const x_handle = document.querySelector('#design-form [name="x_handle"]').value.trim();
                const description = document.querySelector('#design-form [name="description"]').value.trim();
                const background_id = document.querySelector('#design-form [name="background_id"]') ? document.querySelector('#design-form [name="background_id"]').value : '';
                if (window.isDebug) console.log('Form validation:', { email, x_handle, description, background_id, files: myDropzone.getQueuedFiles().length });
                if (!email && !x_handle) {
                    document.querySelector('#dropzone-upload .error-message').style.display = 'block';
                    document.querySelector('#dropzone-upload .error-message').textContent = 'Please provide at least an Email or X Handle.';
                    logError('Validation failed: No email or X handle provided');
                    return;
                }
                if (!description) {
                    document.querySelector('#dropzone-upload .error-message').style.display = 'block';
                    document.querySelector('#dropzone-upload .error-message').textContent = 'Please provide a design description.';
                    logError('Validation failed: No description provided');
                    return;
                }
                if (!background_id) {
                    document.querySelector('#dropzone-upload .error-message').style.display = 'block';
                    document.querySelector('#dropzone-upload .error-message').textContent = 'Please select a background image.';
                    logError('Validation failed: No background selected');
                    return;
                }

                showSubmissionLoadingOverlay();
                await fetchNonce();

                // Step 1: Submit form data
                try {
                    const formResponse = await fetch('/burn-to-earn.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-WP-Nonce': currentNonce
                        },
                        body: new URLSearchParams({
                            action: 'submit_form_data',
                            _wpnonce: currentNonce,
                            xrpl_account: window.xrpl_account,
                            email,
                            x_handle,
                            description,
                            background_id
                        })
                    });
                    const formData = await formResponse.json();
                    if (!formData.success) {
                        hideSubmissionLoadingOverlay();
                        const message = formData.error || 'Failed to submit form data';
                        document.querySelector('#dropzone-upload .error-message').style.display = 'block';
                        document.querySelector('#dropzone-upload .error-message').textContent = message;
                        logError(`Form data submission failed: ${message}`);
                        return;
                    }
                    myDropzone.transient_key = formData.transient_key;
                    if (window.isDebug) console.log('Form data submitted, transient_key:', myDropzone.transient_key);

                    // Step 2: Submit files or manual submit if no files
                    if (myDropzone.getQueuedFiles().length === 0) {
                        const uploadFormData = new FormData();
                        uploadFormData.append('action', 'submit_design');
                        uploadFormData.append('_wpnonce', currentNonce);
                        uploadFormData.append('xrpl_account', window.xrpl_account);
                        uploadFormData.append('transient_key', myDropzone.transient_key);

                        const uploadResponse = await fetch('/burn-to-earn.php', {
                            method: 'POST',
                            body: uploadFormData
                        });
                        const response = await uploadResponse.json();
                        hideSubmissionLoadingOverlay();
                        if (window.isDebug) console.log('Manual upload success (no files):', response);
                        if (response.success) {
                            confetti({ particleCount: 100, spread: 70 });
                            designForm.classList.remove('active');
                            unlockScroll();
                            myDropzone.removeAllFiles();
                            document.querySelector('#dropzone-upload .error-message').style.display = 'none';
                            fetchBurnedCounts().then(updateCounters);
                            fetchHistory('submissions', document.querySelector('#submission-history-table tbody'));
                        } else {
                            const message = response.error || 'Upload failed';
                            if (window.isDebug) console.log('Manual upload failed (no files):', response);
                            logError(`Manual submission error: ${message}`);
                            document.querySelector('#dropzone-upload .error-message').style.display = 'block';
                            document.querySelector('#dropzone-upload .error-message').textContent = message;
                        }
                    } else {
                        myDropzone.processQueue();
                    }
                } catch (error) {
                    hideSubmissionLoadingOverlay();
                    document.querySelector('#dropzone-upload .error-message').style.display = 'block';
                    document.querySelector('#dropzone-upload .error-message').textContent = 'Error submitting form data';
                    logError(`Form data submission error: ${error.message}`);
                }
            });
        } catch (error) {
            logError(`Dropzone initialization failed: ${error.message}`);
        }
    } else {
        logError('Dropzone initialization skipped: missing dropzoneForm, ajax_url, or submitButton');
    }

    // Refresh nonce on page load
    fetchNonce();

    const lockScroll = () => {
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
    };

    const unlockScroll = () => {
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
    };

    // Show submission loading overlay
    const showSubmissionLoadingOverlay = () => {
        let overlay = document.querySelector('#submission-loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'submission-loading-overlay';
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `
                <div class="loading-content">
                    <h2>Submitting Your Custom Design!</h2>
                    <h2>Frequencies Are Being Amplified!</h2>
                    <div class="loading-bar"></div>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'flex';
        lockScroll();
    };

    // Hide submission loading overlay
    const hideSubmissionLoadingOverlay = () => {
        const overlay = document.querySelector('#submission-loading-overlay');
        if (overlay) {
            overlay.style.display = 'none';
            unlockScroll();
        }
    };

    const fetchBurnedCounts = async () => {
        try {
            const nonce = await fetchNonce();
            const response = await fetch(`/burn-to-earn.php?action=burned_counts&account=${encodeURIComponent(window.xrpl_account)}`, {
                headers: { 'X-WP-Nonce': nonce }
            });
            if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
            const data = await response.json();
            if (data.error) {
                logError(`Burned counts fetch error: ${data.error}`);
                return { protectors_freq: 0, protectors_ledger: 0, submission_count: 0 };
            }
            if (window.isDebug) console.log('Burned counts:', data);
            return data;
        } catch (error) {
            logError(`Burned counts fetch failed: ${error.message}`);
            return { protectors_freq: 0, protectors_ledger: 0, submission_count: 0 };
        }
    };

    const fetchBurnedNFTs = async () => {
        try {
            const nonce = await fetchNonce();
            const response = await fetch(`/burn-to-earn.php?action=burned_nfts&account=${encodeURIComponent(window.xrpl_account)}`, {
                headers: { 'X-WP-Nonce': nonce }
            });
            if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
            const data = await response.json();
            if (data.error) {
                logError(`Burned NFTs fetch error: ${data.error}`);
                return [];
            }
            if (window.isDebug) console.log('Burned NFTs:', data.burned_nfts);
            return data.burned_nfts || [];
        } catch (error) {
            logError(`Burned NFTs fetch failed: ${error.message}`);
            return [];
        }
    };

    const fetchNFTs = async () => {
        try {
            // Show loading overlay immediately
            showNftLoadingOverlay('Loading Your NFTs');
            updateNftLoadingProgress(0, 0, 0, 0, 'Connecting to wallet...');
            
            const nonce = await fetchNonce();
            updateNftLoadingProgress(0, 0, 0, 0, 'Fetching your NFT collection...');
            
            const burnedNFTs = await fetchBurnedNFTs();
            
            // Check if we need to force refresh (after a burn)
            const forceRefresh = sessionStorage.getItem('burn_completed') === 'true';
            if (forceRefresh) {
                sessionStorage.removeItem('burn_completed');
                if (window.isDebug) console.log('Force refresh after burn');
            }
            
            // Use xumm-proxy.php - it has server-side caching via WordPress transients
            // Only add force_check if we just completed a burn
            const forceParam = forceRefresh ? '&force_check=true' : '';
            const url = `/xumm-proxy.php?account=${encodeURIComponent(window.xrpl_account)}&t=${Date.now()}${forceParam}`;
            
            if (window.isDebug) console.log('Fetching account NFTs from:', url);
            
            const response = await fetch(url, {
                headers: { 'X-WP-Nonce': nonce }
            });
            
            if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
            
            const data = await response.json();
            
            if (data.result?.status === 'error') {
                logError(`NFT fetch error: ${data.result.error}`);
                nftGrid.innerHTML = '<p>Error loading NFTs. Please refresh the page.</p>';
                return;
            }
            
            // Filter for burnable collections only
            let nfts = (data.result?.account_nfts || []).filter(nft =>
                (nft.Issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && nft.NFTokenTaxon === 717825) ||
                (nft.Issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && nft.NFTokenTaxon === 1056369418)
            );
            
            // Exclude already burned NFTs
            nfts = nfts.filter(nft => !burnedNFTs.includes(nft.NFTokenID));
            
            if (window.isDebug) console.log('Filtered NFTs for burn:', nfts.length);
            
            // Update loading with NFT count
            updateNftLoadingProgress(0, nfts.length, 0, 0, `Found ${nfts.length} burnable NFTs`);
            
            if (nfts.length === 0) {
                hideNftLoadingOverlay();
                nftGrid.innerHTML = '<p>No eligible NFTs found for burning.</p>';
                const burnedCounts = await fetchBurnedCounts();
                updateCounters(burnedCounts);
                return;
            }
            
            // Render skeleton cards immediately so the user sees the grid at once
            nfts.forEach(nft => {
                nft.metadata = { name: 'Loading...', image: '/wp-content/uploads/fallback-nft.svg' };
            });
            renderNFTGrid(nfts);
            
            // Fetch metadata progressively — each completed batch updates cards in-place
            // No second renderNFTGrid() call needed; updateCardImage() patches the DOM live
            await fetchNFTMetadata(nfts);
            
            // Hide loading overlay
            hideNftLoadingOverlay();
            
            const burnedCounts = await fetchBurnedCounts();
            updateCounters(burnedCounts);
            
        } catch (error) {
            logError(`NFT fetch failed: ${error.message}`);
            hideNftLoadingOverlay();
            nftGrid.innerHTML = '<p>Error loading NFTs. Please try again.</p>';
        }
    };

    const fetchNFTMetadata = async (nfts) => {
        if (!nfts || nfts.length === 0) return;

        const FAILED_IMAGE = '/wp-content/uploads/fallback-nft.svg';

        // Build VPS image URL from edition number in name — for POTL/POTF
        const resolveVpsImage = (issuer, name) => {
            if (!name) return null;
            const m = name.match(/#(\d+)/);
            if (!m) return null;
            const ed = m[1];
            if (issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt')
                return `https://images.imcollectibles.io/ledger/protector-${ed}.png`;
            if (issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga')
                return `https://images.imcollectibles.io/frequencies/protector-${ed}.png`;
            return null;
        };

        // ── SINGLE BATCH: mirror my-nfts-handler approach exactly ───────────────
        // POST all NFT IDs at once to my-nfts-handler.php?action=get_metadata.
        // That handler calls the VPS indexer (?action=batch) directly — NO Bithomp,
        // NO per-batch rate limits, handles 100s of NFTs server-side in one call.
        // This is identical to how My NFTs page loads the same collections reliably.
        const nonce = window.xrplMarketplace?.nonce || '';
        const handlerUrl = window.xrplMarketplace?.myNftsHandler;

        if (!handlerUrl) {
            console.error('[B2E] myNftsHandler URL not available — falling back to batch mode');
            // Graceful degradation: mark all as failed so cards show the error SVG
            nfts.forEach(nft => {
                const img = resolveVpsImage(nft.Issuer, null) || FAILED_IMAGE;
                nft.metadata = { name: nft.metadata?.name || 'Unnamed NFT', image: img };
                updateCardImage(nft.NFTokenID, img, nft.metadata.name);
            });
            return;
        }

        updateNftLoadingProgress(0, nfts.length, 1, 1, 'Fetching metadata...');

        const allIds = nfts.map(n => n.NFTokenID).filter(Boolean);
        let metadataMap = {};

        try {
            if (window.isDebug) console.log(`[B2E] Fetching metadata for ${allIds.length} NFTs via my-nfts-handler (VPS direct)`);
            const resp = await fetch(
                `${handlerUrl}?action=get_metadata&nonce=${encodeURIComponent(nonce)}`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: allIds })
                }
            );

            if (resp.ok) {
                const data = await resp.json();
                if (data.success && data.metadata) {
                    metadataMap = data.metadata;
                    if (window.isDebug) console.log(`[B2E] Received metadata for ${Object.keys(metadataMap).length}/${allIds.length} NFTs`);
                } else {
                    console.warn('[B2E] get_metadata returned no metadata:', data);
                }
            } else {
                console.error('[B2E] get_metadata HTTP error:', resp.status);
            }
        } catch (err) {
            console.error('[B2E] get_metadata fetch error:', err.message);
        }

        // Apply metadata to each NFT and update its card in-place
        nfts.forEach(nft => {
            // my-nfts-handler keys metadata by uppercase ID
            const meta = metadataMap[nft.NFTokenID]
                      || metadataMap[nft.NFTokenID.toUpperCase()]
                      || metadataMap[nft.NFTokenID.toLowerCase()]
                      || null;

            let name  = meta?.name  || nft.metadata?.name || 'Unnamed NFT';

            // Priority 1: name-based VPS image for POTL/POTF (resolveVpsImage parses #NNN from name)
            // The VPS indexes all NFT names — name = 'Protectors of the Ledger #1578' -> edition=1578
            let image = null;
            const vps = resolveVpsImage(nft.Issuer, name);
            if (vps) image = vps;

            // Priority 2: image_proxy from VPS (img.php?nft=ID — VPS-cached, no IPFS latency)
            // Covers all other collection types and any POTL/POTF not resolved via name
            if (!image) image = meta?.image_proxy || null;

            // Priority 3: raw image URL from VPS metadata (Pinata/IPFS gateway URL)
            if (!image) {
                image = meta?.image || null;
                // Normalise ipfs.io -> Pinata
                if (image && image.includes('ipfs.io/ipfs/'))
                    image = image.replace('https://ipfs.io/ipfs/',
                                          'https://<your-pinata-gateway>/ipfs/');
            }

            if (!image) image = FAILED_IMAGE;

            // v349 FIX: build rawFallbackUrl for retry #2.
            // When the primary image is the CDN URL (Priority 1 — resolveVpsImage),
            // retry #2 was previously also the CDN URL (same URL = guaranteed same failure).
            // Now we pass meta.image (Pinata URL from VPS) as the fallback so retry #2
            // tries a completely different path, identical to how my-nfts loads these NFTs.
            let rawFallbackUrl = null;
            if (meta?.image) {
                let rf = meta.image;
                if (rf.includes('ipfs.io/ipfs/'))
                    rf = rf.replace('https://ipfs.io/ipfs/',
                                    'https://<your-pinata-gateway>/ipfs/');
                // Only useful as fallback if it's different from the primary image
                if (rf !== image) rawFallbackUrl = rf;
            }

            nft.metadata = { name, image,
                description: meta?.description || '',
                attributes:  meta?.attributes  || [] };

            updateCardImage(nft.NFTokenID, image, name, rawFallbackUrl);
        });

        updateNftLoadingProgress(nfts.length, nfts.length, 1, 1, 'Images loaded');
    };


    // ── Throttled image loader ────────────────────────────────────────────────
    //
    // Three bugs in the original code caused the failures seen in the recording:
    //
    // BUG 1 — src="" fires onerror immediately on Android/Chrome:
    //   Cards were rendered with <img src=""> which triggers onerror before
    //   updateCardImage could assign the real URL. The error handler ran first,
    //   marking cards as failed — then updateCardImage overwrote with the real
    //   src, leaving a confusing half-error state. Fixed by rendering with no
    //   src attribute at all (see renderNFTGrid above).
    //
    // BUG 2 — t=${Date.now()} on every img.php request defeats VPS caching:
    //   Each updateCardImage call built a unique URL (different timestamp),
    //   so the VPS HTTP cache got zero hits. With 100+ NFTs all firing at once
    //   that meant 100+ simultaneous cold-cache requests to img.php. The proxy
    //   was overwhelmed, timeouts cascaded, everything "failed". Fixed by using
    //   the resolved imageUrl directly (already a stable CDN URL or img.php
    //   without t=). Cache-busting only happens in retry chain where it's needed.
    //
    // BUG 3 — All images fire simultaneously (nfts.forEach → updateCardImage):
    //   Even with good caching, 100+ concurrent requests stress any server.
    //   Fixed with a throttled queue (MAX_CONCURRENT = 6) + IntersectionObserver
    //   that promotes visible cards to the front so above-fold NFTs load first.
    //
    // ─────────────────────────────────────────────────────────────────────────

    const MAX_CONCURRENT = 6;   // max simultaneous image requests
    let   _b2eActive = 0;
    const _b2eQueue  = [];      // { card, img, wrapper, nftId, imageUrl, name }

    // Drain the queue — called whenever a slot frees up or a new item is added
    const _b2eDrain = () => {
        while (_b2eActive < MAX_CONCURRENT && _b2eQueue.length > 0) {
            // Prefer a card that is already in or near the viewport
            let pick = 0;
            for (let i = 0; i < _b2eQueue.length; i++) {
                const r = _b2eQueue[i].card.getBoundingClientRect();
                if (r.top < window.innerHeight + 400) { pick = i; break; }
            }
            const entry = _b2eQueue.splice(pick, 1)[0];
            const { card, img, wrapper, nftId, imageUrl, name, rawFallbackUrl } = entry;

            // Skip if the card was removed from the DOM (e.g. after a burn)
            if (!document.contains(card)) { _b2eDrain(); continue; }

            _b2eActive++;

            // Attach handlers to the img element now — not as inline attributes.
            // This avoids race conditions where inline onerror fires before src is set.
            img.onload = () => {
                if (wrapper) wrapper.classList.remove('loading');
                _b2eActive--;
                _b2eDrain();
            };
            img.onerror = () => {
                _b2eActive--;
                _b2eDrain();
                b2eHandleImageError(img, nftId);
            };

            // Use imageUrl directly as src:
            //   • images.imcollectibles.io/ledger/protector-N.png   (POTL)
            //   • images.imcollectibles.io/frequencies/protector-N.png  (POTF)
            //   • metadata.imcollectibles.io/img.php?nft=ID  (image_proxy — NO t=)
            //   • Pinata/IPFS CDN URL  (raw image from VPS metadata)
            // All are stable cacheable URLs. The VPS and browser caches both work.
            // img.php (without t=) is stored in data-original-src for the retry chain.
            const proxyUrl = `https://metadata.imcollectibles.io/img.php?nft=${encodeURIComponent(nftId)}&thumb=1`;
            // dataset.rawImage is the URL tried at retry #2.
            // v349 FIX: use rawFallbackUrl (Pinata) when provided so retry #2 is a
            // genuinely different request. Without this, POTL/POTF retry #2 was
            // attempting the same images.imcollectibles.io URL that already failed,
            // giving identical failure → immediate fallback SVG with no Pinata attempt.
            img.dataset.rawImage    = rawFallbackUrl || imageUrl;
            img.dataset.originalSrc = proxyUrl;   // img.php no-cache-bust (retry #0,#1)
            img.dataset.retryCount  = '0';
            delete img.dataset.manualRetry;

            if (name) img.alt = name;

            // Set src last — this is the moment the browser starts the request
            img.src = imageUrl;
        }
    };

    // IntersectionObserver: when a queued card scrolls into view, move it to the
    // front so it loads before off-screen cards.
    const _b2eIO = ('IntersectionObserver' in window)
        ? new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                _b2eIO.unobserve(entry.target);
                const qi = _b2eQueue.findIndex(e => e.card === entry.target);
                if (qi > 0) _b2eQueue.unshift(_b2eQueue.splice(qi, 1)[0]);
                _b2eDrain();
            });
          }, { rootMargin: '400px' })
        : null;

    // Public entry-point: queue a card's image and update its title instantly.
    // rawFallbackUrl (optional): a *different* URL to try at retry #2.
    // For POTL/POTF the primary URL is the CDN (images.imcollectibles.io).
    // rawFallbackUrl should be the Pinata URL from meta.image so that if the
    // CDN fails, retry #2 tries Pinata rather than the same CDN URL again.
    const updateCardImage = (nftId, imageUrl, name, rawFallbackUrl = null) => {
        const card = nftGrid.querySelector(`.nft-card[data-nft-id="${nftId}"]`);
        if (!card) return;

        // Update the title immediately — zero network cost
        if (name && name !== 'Loading...') {
            const h4 = card.querySelector('h4');
            if (h4) h4.textContent = name;
        }

        const img     = card.querySelector('.nft-image');
        if (!img) return;
        const wrapper = img.closest('.nft-image-wrapper');

        // Add to queue and start the observer so viewport entry promotes it
        _b2eQueue.push({ card, img, wrapper, nftId, imageUrl, name, rawFallbackUrl });
        if (_b2eIO) _b2eIO.observe(card);
        _b2eDrain();
    };

    // ── Scoped image error handler ───────────────────────────────────────────
    // Retry chain:
    //   retry 0 → cache-bust img.php (1 s delay)
    //   retry 1 → cache-bust img.php + nocache=1 to purge VPS cache (2 s delay)
    //   retry 2 → try data-raw-image (direct CDN/Pinata URL) if different
    //   retry 3 → fallback SVG + manual Retry button
    const b2eHandleImageError = (imgEl, nftId) => {
        // Don't handle errors on the fallback SVG itself
        if (imgEl.src && imgEl.src.includes('fallback-nft.svg')) return;

        let retry = parseInt(imgEl.dataset.retryCount || '0', 10);
        const rawUrl = imgEl.dataset.rawImage;

        // Preserve original src on first error so retries always start from the same URL
        if (!imgEl.dataset.originalSrc) imgEl.dataset.originalSrc = imgEl.src;
        const originalSrc = imgEl.dataset.originalSrc;

        // Cache-bust helper: strips existing t= and nocache params cleanly
        const bustUrl = (url, extra) => {
            const stripped = url.replace(/[?&]t=\d+/g, '')
                                .replace(/[?&]nocache=1/g, '')
                                .replace(/&&/g, '&').replace(/[?&]$/, '');
            const sep = stripped.includes('?') ? '&' : '?';
            return stripped + sep + `t=${Date.now()}` + (extra ? `&${extra}` : '');
        };

        // Add shimmer to wrapper while retrying
        const wrapper = imgEl.closest('.nft-image-wrapper');
        if (wrapper) wrapper.classList.add('loading');

        if (retry < 2) {
            // Auto-retries 0–1: cache-bust; retry 1 adds nocache=1 to purge stale VPS cache
            retry++;
            imgEl.dataset.retryCount = String(retry);
            const extra = retry === 2 ? 'nocache=1' : '';
            setTimeout(() => { imgEl.src = bustUrl(originalSrc, extra); }, 1000 * retry);

        } else if (retry === 2 && rawUrl && rawUrl !== originalSrc) {
            // Auto-retry 2: try the direct image URL (e.g. images.imcollectibles.io/ledger/...)
            retry++;
            imgEl.dataset.retryCount = String(retry);
            setTimeout(() => { imgEl.src = bustUrl(rawUrl, ''); }, 1000);

        } else {
            // All retries exhausted — show fallback + Retry button on the card
            if (wrapper) wrapper.classList.remove('loading');
            imgEl.src = '/wp-content/uploads/fallback-nft.svg';
            imgEl.removeAttribute('onerror');

            const card = imgEl.closest('.nft-card');
            if (!card) return;
            card.classList.add('error');

            // Remove any existing retry button
            const existing = card.querySelector('.refresh-nft');
            if (existing) existing.remove();

            const refreshBtn = document.createElement('button');
            refreshBtn.className = 'refresh-nft';
            refreshBtn.textContent = 'Retry';

            const doRetry = (e) => {
                e.stopPropagation();
                e.stopImmediatePropagation();
                e.preventDefault();
                card.classList.remove('error');
                if (wrapper) wrapper.classList.add('loading');
                refreshBtn.remove();
                // Force nocache re-fetch from originalSrc (VPS proxy)
                const base = imgEl.dataset.originalSrc ||
                             `https://metadata.imcollectibles.io/img.php?nft=${encodeURIComponent(nftId)}`;
                const sep = base.includes('?') ? '&' : '?';
                imgEl.dataset.retryCount = '0';
                imgEl.dataset.manualRetry = '1';
                imgEl.onerror = () => b2eHandleImageError(imgEl, nftId);
                imgEl.src = `${base}${sep}nocache=1&t=${Date.now()}`;
            };

            refreshBtn.addEventListener('click',      doRetry);
            refreshBtn.addEventListener('pointerup',  doRetry);
            refreshBtn.addEventListener('pointerdown', (e) => e.stopPropagation());
            refreshBtn.addEventListener('touchend',   doRetry);
            card.appendChild(refreshBtn);
        }
    };

    const renderNFTGrid = (nfts) => {
        nftGrid.innerHTML = '';
        const frag = document.createDocumentFragment();
        nfts.forEach((nft) => {
            const card = document.createElement('div');
            card.className = `nft-card ${selectedNFT === nft.NFTokenID ? 'selected' : ''} visible`;
            card.dataset.nft = '{}';
            card.dataset.issuer = nft.Issuer;
            card.dataset.nftId = nft.NFTokenID;
            card.dataset.taxon = nft.NFTokenTaxon;
            // IMPORTANT: no src attribute on the img — an empty src="" fires onerror
            // immediately on Android/Chrome before updateCardImage can set the real URL.
            // We leave src unset; updateCardImage (via the throttled queue) sets it
            // once metadata arrives. The .loading shimmer overlay covers the blank state.
            card.innerHTML = `
                <div class="nft-image-wrapper loading">
                    <img alt="Loading..." class="nft-image"
                         data-retry-count="0" data-nft-id="${nft.NFTokenID}">
                    <div class="nft-loading-spinner"></div>
                </div>
                <h4>Loading...</h4>
                <input type="checkbox" class="nft-checkbox" data-nft-id="${nft.NFTokenID}"
                       ${selectedNFT === nft.NFTokenID ? 'checked' : ''} aria-label="Select NFT">
            `;
            frag.appendChild(card);
        });
        nftGrid.appendChild(frag);

        nftGrid.querySelectorAll('.nft-checkbox').forEach(checkbox => {
            checkbox.removeEventListener('change', handleNFTSelection);
            checkbox.addEventListener('change', handleNFTSelection);
        });

        nftGrid.querySelectorAll('.nft-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.classList.contains('nft-checkbox')) return;
                e.stopPropagation();
            });
        });
    };

    // ── Burn-to-earn image error handler ───────────────────────────────────────
    window.b2eHandleImageError = b2eHandleImageError;
    // Only claim the global slot if trading.js hasn't already registered its own version
    if (!window.handleImageError) window.handleImageError = b2eHandleImageError;

    const handleNFTSelection = (e) => {
        const nftId = e.target.dataset.nftId;
        if (e.target.checked) {
            if (selectedNFT === null) {
                selectedNFT = nftId;
            } else {
                e.target.checked = false;
                alert('You can select only 1 NFT at a time.');
            }
        } else {
            selectedNFT = null;
        }
        updateBurnRow();
    };

    const updateBurnRow = async () => {
        burnRow.style.display = selectedNFT ? 'block' : 'none';
        burnRow.innerHTML = selectedNFT ? '<h3>Selected NFT to Burn</h3>' : '';
        if (selectedNFT) {
            const metadata = await getNFTMetadata(selectedNFT);
            const cacheBuster = `?t=${Date.now()}`;
            const row = document.createElement('div');
            row.className = 'burn-row-item';
            row.innerHTML = `
                <img src="${metadata.image}${cacheBuster}" alt="${metadata.name}" width="50" height="50">
                <span class="nft-name">${metadata.name}</span>
                <span class="status">Pending</span>
                <button class="burn-btn" data-nft-id="${selectedNFT}">Burn</button>
            `;
            burnRow.appendChild(row);
        }
        document.querySelectorAll('.burn-btn').forEach(btn => {
            btn.removeEventListener('click', initiateBurn);
            btn.addEventListener('click', () => initiateBurn(btn.dataset.nftId));
        });
    };

    const getNFTMetadata = async (nftId) => {
        try {
            const nonce = await fetchNonce();
            const response = await fetch(`/xumm-proxy.php?ids=${nftId}&t=${Date.now()}`, {
                headers: { 'X-WP-Nonce': nonce }
            });
            if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
            const data = await response.json();
            if (!data.success || !data.nfts || !data.nfts[0]) {
                logError(`Metadata fetch failed for NFT ${nftId}: No valid data`);
                return { name: 'Unnamed NFT', image: '/wp-content/uploads/fallback-nft.svg' };
            }
            return data.nfts[0].metadata;
        } catch (error) {
            logError(`Metadata fetch failed for NFT ${nftId}: ${error.message}`);
            return { name: 'Unnamed NFT', image: '/wp-content/uploads/fallback-nft.svg' };
        }
    };

    const showQRPopup = (qrSrc, uuid, deeplink) => {
    const popup = document.createElement('div');
    popup.className = 'history-popup';
    popup.style.zIndex = '1000';
    popup.innerHTML = `
        <div class="popup-content">
            <button class="close-popup">Close</button>
            <h3>Scan to Burn NFT</h3>
            <div class="burn-qr">
                <img src="${qrSrc}" alt="Scan to Burn" style="max-width: 300px;">
            </div>
            <p>Mobile users: Use the button below to open in Xumm wallet.</p>
            <a href="${deeplink}" class="deeplink-button" target="_blank" rel="noopener">Open in Xumm Wallet</a>
            <p id="burn-status">Waiting for transaction to be signed...</p>
        </div>
    `;
    document.body.appendChild(popup);
    lockScroll();
    document.querySelector('.close-popup').addEventListener('click', () => {
        popup.remove();
        unlockScroll();
    });
    popup.addEventListener('click', (e) => {
        if (e.target === popup) {
            popup.remove();
            unlockScroll();
        }
    });
    return popup;
};

    const showLoadingOverlay = () => {
        let overlay = document.querySelector('#loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'loading-overlay';
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `
                <div class="loading-content">
                    <h2>These Protectors are stronger than we thought!</h2>
                    <h2>Please hold tight as the Firepit is in action!</h2>
                    <div class="loading-bar"></div>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'flex';
        lockScroll();
    };

    const hideLoadingOverlay = () => {
        const overlay = document.querySelector('#loading-overlay');
        if (overlay) {
            overlay.style.display = 'none';
            unlockScroll();
        }
    };

    // ============================================================================
    // NFT LOADING VISUALIZER - Shows progress while fetching NFTs and metadata
    // ============================================================================
    
    let nftLoadingState = {
        totalNfts: 0,
        loadedNfts: 0,
        currentBatch: 0,
        totalBatches: 0,
        startTime: 0
    };

    const showNftLoadingOverlay = (message = 'Loading Your NFTs') => {
        let overlay = document.querySelector('#nft-loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'nft-loading-overlay';
            overlay.className = 'nft-loading-overlay';
            overlay.innerHTML = `
                <div class="nft-loader-content">
                    <div class="fire-loader-animation">🔥</div>
                    <h2 class="nft-loading-title">${message}</h2>
                    <p class="nft-loading-subtitle">Preparing the Frequency Firepit...</p>
                    <div class="nft-progress-container">
                        <div class="nft-progress-bar" id="nft-progress-bar"></div>
                    </div>
                    <div class="nft-progress-stats">
                        <span id="nft-load-count">Connecting to wallet...</span>
                        <span id="nft-load-percent">0%</span>
                    </div>
                    <div class="nft-batch-indicator" id="nft-batch-indicator"></div>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'flex';
        overlay.classList.remove('fade-out');
        nftLoadingState.startTime = Date.now();
        lockScroll();
    };

    const updateNftLoadingProgress = (loaded, total, batch = 0, totalBatches = 0, status = '') => {
        nftLoadingState.loadedNfts = loaded;
        nftLoadingState.totalNfts = total;
        nftLoadingState.currentBatch = batch;
        nftLoadingState.totalBatches = totalBatches;

        const percent = total > 0 ? Math.round((loaded / total) * 100) : 0;
        
        const progressBar = document.getElementById('nft-progress-bar');
        if (progressBar) {
            progressBar.style.width = percent + '%';
        }
        
        const countEl = document.getElementById('nft-load-count');
        if (countEl) {
            if (status) {
                countEl.textContent = status;
            } else if (total > 0) {
                countEl.textContent = `${loaded} / ${total} NFTs loaded`;
            }
        }
        
        const percentEl = document.getElementById('nft-load-percent');
        if (percentEl) {
            percentEl.textContent = percent + '%';
        }
        
        const batchEl = document.getElementById('nft-batch-indicator');
        if (batchEl && totalBatches > 0) {
            batchEl.textContent = `Fetching metadata batch ${batch} of ${totalBatches}`;
        } else if (batchEl && status) {
            batchEl.textContent = '';
        }

        const subtitle = document.querySelector('.nft-loading-subtitle');
        if (subtitle) {
            if (percent < 25) {
                subtitle.textContent = 'Preparing the Frequency Firepit...';
            } else if (percent < 50) {
                subtitle.textContent = 'Gathering your burnable NFTs...';
            } else if (percent < 75) {
                subtitle.textContent = 'Loading NFT artwork...';
            } else {
                subtitle.textContent = 'Almost ready to burn!';
            }
        }
    };

    const hideNftLoadingOverlay = () => {
        const overlay = document.querySelector('#nft-loading-overlay');
        if (overlay) {
            const elapsed = Date.now() - nftLoadingState.startTime;
            const minDisplay = 800; // Minimum display time to prevent flash
            
            const doHide = () => {
                overlay.classList.add('fade-out');
                setTimeout(() => {
                    overlay.style.display = 'none';
                    overlay.classList.remove('fade-out');
                    unlockScroll();
                }, 300);
            };
            
            if (elapsed < minDisplay) {
                setTimeout(doHide, minDisplay - elapsed);
            } else {
                doHide();
            }
        }
    };

    const initiateBurn = async (nftId) => {
    try {
        const nonce = await fetchNonce();
        // --- Joey branch (v553, additive): sign NFTokenBurn locally, then verify on-chain. ---
        if (window.__joeySession && window.__joeySession.live && window.imuWallet) {
            if (window.xrpl_account !== window.__joeySession.account) { alert('Connected wallet does not match your account. Please reconnect.'); return; }
            const jres = await fetch('/burn-to-earn.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ action: 'burn_single_nft', nft_id: nftId, wallet: 'joey' })
            });
            const jd = await jres.json();
            if (!jd.success || !jd.txjson) throw new Error(jd.error || 'Failed to prepare burn');
            let jsigned;
            try { jsigned = await (window.imuJoeySign||window.imuWallet.sign)(jd.txjson); }
            catch (e) { const em=(e&&e.message)||''; alert(/timed out|timeout/i.test(em)?'Signing timed out. Please try again.':/cancel/i.test(em)?'Burn cancelled.':'Burn rejected.'); return; }
            const jtx = jsigned && (jsigned.hash || jsigned.tx_hash);
            if (!jtx) { alert('No transaction hash returned from your wallet.'); return; }
            const vres = await fetch('/burn-to-earn.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ action: 'joey_verify_burn', nft_id: nftId, tx_hash: jtx })
            });
            const vd = await vres.json();
            if (!vd.success) throw new Error(vd.error || 'Burn verification failed');
            sessionStorage.setItem('burn_completed', 'true');
            selectedNFT = null;
            updateBurnRow();
            fetchNFTs();
            fetchHistory('transfers', document.querySelector('#burn-history-table tbody'));
            alert('Burn confirmed! 🔥');
            return;
        }
                const response = await fetch('/burn-to-earn.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ action: 'burn_single_nft', nft_id: nftId })
        });
        if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
        const data = await response.json();
        if (data.error) {
            logError(`Burn initiation error: ${data.error}`);
            alert(data.error);
            return;
        }
        // v684: burn-to-earn.php is now server-authoritative on wallet type too, so a joey-cookie
        // user with no live WalletConnect session reaches this Xaman path and receives a Joey
        // txjson -- no qr, no uuid. Bounce before opening a blank popup that polls undefined.
        // Note the server has already inserted the 'pending' transfers row by this point, exactly
        // as the Xaman path does when a user never signs; it ages out the same way.
        if (data.wallet === 'joey' && data.txjson) {
            alert('Wallet still connecting — please try again in a moment.');
            return;
        }
        const qrPopup = showQRPopup(data.qr, data.uuid, data.deeplink);
        pollBurnStatus(data.uuid, data.nft_id, qrPopup);
    } catch (error) {
        logError(`Burn initiation failed: ${error.message}`);
        alert('Failed to initiate burn.');
    }
};

    const pollBurnStatus = async (uuid, nftId, qrPopup) => {
    let attempts = 0;
    let signedAttempts = 0;
    const maxAttempts = 60; // Increased to ~2min for longer confirmation
    const maxSignedAttempts = 30; // ~1min post-sign
    const interval = 2000; // 2s as per guide
    const statusElement = qrPopup.querySelector('#burn-status');
    let tx_hash = null;
    const poll = setInterval(async () => {
        try {
            const nonce = await fetchNonce();
            const response = await fetch(`/burn-to-earn.php?action=transfer_status&uuid=${uuid}&account=${encodeURIComponent(window.xrpl_account)}&type=transfers`, {
    headers: { 'X-WP-Nonce': nonce }
});
            if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
            const data = await response.json();
            if (window.isDebug) console.log(`Poll attempt ${attempts + 1} for UUID ${uuid}:`, data);
            if (data.error) {
                clearInterval(poll);
                statusElement.textContent = `Error: ${data.error}`;
                hideLoadingOverlay();
                return;
            }
            if (data.signed && data.transfers.length && data.transfers[0]?.status === 'confirmed') {
                clearInterval(poll);
                statusElement.textContent = 'Burn confirmed! Updating your account...';
                hideLoadingOverlay();
                sessionStorage.setItem('burn_completed', 'true'); // Force XRPL refresh on next fetchNFTs
                setTimeout(() => {
                    qrPopup.remove();
                    selectedNFT = null;
                    updateBurnRow();
                    fetchNFTs();
                    fetchHistory('transfers', document.querySelector('#burn-history-table tbody'));
                }, 2000);
            } else if (data.signed && data.transfers.length && data.transfers[0]?.status === 'pending_confirmation') {
                statusElement.textContent = 'Transaction signed, awaiting confirmation...';
                showLoadingOverlay();
                signedAttempts++;
            } else if (data.signed) {
                statusElement.textContent = 'Transaction signed, processing...';
                showLoadingOverlay();
                signedAttempts++;
                if (!data.transfers.length && signedAttempts < maxSignedAttempts) {
                    await fetchNonce();
                }
            } else {
                statusElement.textContent = 'Waiting for transaction to be signed...';
                hideLoadingOverlay();
            }
            if (signedAttempts >= maxSignedAttempts && data.signed && (!data.transfers.length || data.transfers[0]?.status !== 'confirmed')) {
                clearInterval(poll);
                statusElement.textContent = 'Burn processed, updating your account...';
                hideLoadingOverlay();
                sessionStorage.setItem('burn_completed', 'true');
                setTimeout(() => {
                    qrPopup.remove();
                    selectedNFT = null;
                    updateBurnRow();
                    fetchNFTs();
                    fetchHistory('transfers', document.querySelector('#burn-history-table tbody'));
                }, 2000);
            }
            if (++attempts >= maxAttempts) {
                clearInterval(poll);
                statusElement.textContent = 'Transaction timed out. Please check manually.';
                hideLoadingOverlay();
            }
        } catch (error) {
            clearInterval(poll);
            statusElement.textContent = 'Error checking status. Please try again.';
            hideLoadingOverlay();
            logError(`Poll burn status failed for UUID ${uuid}: ${error.message}`);
        }
    }, interval);
};

    const updateCounters = (data) => {
        try {
            freqCounter.textContent = data.protectors_freq || 0;
            ledgerCounter.textContent = data.protectors_ledger || 0;
            const totalBurned = (data.protectors_freq || 0) + (data.protectors_ledger || 0);
            const submissionCount = data.submission_count || 0;
            const availableSubmissions = Math.floor(totalBurned / 10) - submissionCount;

            pendingBurnedCount = totalBurned;
            const progressFill = document.querySelector('#progress-bar .progress-bar-fill');
            if (progressFill) {
                progressFill.style.width = availableSubmissions > 0 ? '100%' : `${Math.min((totalBurned % 10) * 10, 100)}%`;
            }
            document.querySelector('#progress-bar + p').textContent = `${pendingBurnedCount}/10 NFTs burned until next exclusive NFT!`;

            designBtn.style.display = availableSubmissions > 0 ? 'block' : 'none';
            if (availableSubmissions > 0) {
                designBtn.textContent = `Design Your Protector (x${availableSubmissions})`;
            }
        } catch (error) {
            logError(`Update counters failed: ${error.message}`);
        }
    };

    const showDesignForm = async () => {
        try {
            if (!backgroundImages || !Array.isArray(backgroundImages)) {
                throw new Error('backgroundImages is not defined or not an array');
            }
            designForm.classList.toggle('active');
            if (designForm.classList.contains('active')) {
                lockScroll();
                document.querySelector('#design-form [name="xrpl_account"]').value = window.xrpl_account;
                const carousel = document.querySelector('.background-carousel');
                carousel.innerHTML = '';
                backgroundImages.forEach((image, index) => {
                    const option = document.createElement('div');
                    option.className = 'background-option';
                    option.dataset.backgroundId = index + 1;
                    option.innerHTML = `
                        <img src="${image}" alt="Background ${index + 1}" ${index === 0 ? 'class="selected"' : ''} loading="lazy">
                        ${index === 0 ? '<input type="hidden" name="background_id" value="' + (index + 1) + '">' : ''}
                    `;
                    carousel.appendChild(option);
                });
                document.querySelectorAll('.background-option').forEach(option => {
                    option.addEventListener('click', () => {
                        document.querySelectorAll('.background-option img').forEach(img => img.classList.remove('selected'));
                        document.querySelectorAll('#design-form [name="background_id"]').forEach(input => input.remove());
                        option.querySelector('img').classList.add('selected');
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'background_id';
                        input.value = option.dataset.backgroundId;
                        option.appendChild(input);
                    });
                });
            } else {
                unlockScroll();
            }
        } catch (error) {
            logError(`Show design form failed: ${error.message}`);
            document.querySelector('.burn-to-earn-container').innerHTML = '<p>Error loading design form. Please try again later.</p>';
        }
    };

    designBtn.addEventListener('click', showDesignForm);
    designForm.querySelector('.close-form').addEventListener('click', () => {
        designForm.classList.remove('active');
        unlockScroll();
    });

    filterSelect.addEventListener('change', () => {
        try {
            const value = filterSelect.value;
            document.querySelectorAll('.nft-card').forEach(card => {
                const issuer = card.dataset.issuer;
                const taxon = parseInt(card.dataset.taxon);
                if (value === 'all' ||
                    (value === 'frequency' && (
                        (issuer === 'rDVcsu44k6KeZMGBH3pPTTpjyNQXim9aga' && taxon === 717825) ||
                        (issuer === 'rf1MGf4U8CZzb2NDGa5qXPFm4eM39zKq9U' && taxon === 1)
                    )) ||
                    (value === 'ledger' && issuer === 'raDwQzjBoKQ8xzFqKKrAY9JKtuuqWQ3PLt' && taxon === 1056369418)) {
                    card.classList.remove('hidden');
                    card.classList.add('visible');
                } else {
                    card.classList.add('hidden');
                    card.classList.remove('visible');
                }
            });
        } catch (error) {
            logError(`Filter select change failed: ${error.message}`);
        }
    });

    const showPopup = (tableId, type) => {
        try {
            const popup = document.createElement('div');
            popup.className = 'history-popup';
            popup.style.zIndex = '1000';
            popup.innerHTML = `
                <div class="popup-content">
                    <button class="close-popup">Close</button>
                    <h3>${tableId === 'burn-history' ? 'Burn History' : 'Design History'}</h3>
                    <table id="${tableId}-popup">
                        <thead><tr><th>ID</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            `;
            document.body.appendChild(popup);
            lockScroll();
            document.querySelector('.close-popup').addEventListener('click', () => {
                popup.remove();
                unlockScroll();
            });
            popup.addEventListener('click', (e) => {
                if (e.target === popup) {
                    popup.remove();
                    unlockScroll();
                }
            });
            fetchHistory(type, document.querySelector(`#${tableId}-popup tbody`));
        } catch (error) {
            logError(`Show popup failed: ${tableId}, ${error.message}`);
        }
    };

    historyToggle.addEventListener('click', () => showPopup('burn-history', 'transfers'));
    submissionToggle.addEventListener('click', () => showPopup('submission-history', 'submissions'));

    const fetchHistory = async (type, tbody) => {
        try {
            const nonce = await fetchNonce();
            const response = await fetch(`/burn-to-earn.php?action=transfer_status&type=${type}&account=${encodeURIComponent(window.xrpl_account)}`, {
                headers: { 'X-WP-Nonce': nonce }
            });
            if (!response.ok) throw new Error('Network response was not ok: ' + response.status);
            const data = await response.json();
            renderHistory(data[type], tbody);
            if (tbody.id === 'burn-history-table-tbody') {
                renderHistory(data.transfers.slice(0, 3), document.querySelector('#burn-history-table tbody'));
            } else if (tbody.id === 'submission-history-table-tbody') {
                renderHistory(data.submissions.slice(0, 3), document.querySelector('#submission-history-table tbody'));
            }
        } catch (error) {
            logError(`History fetch failed for ${type}: ${error.message}`);
        }
    };

    const renderHistory = (items, tbody) => {
        try {
            tbody.innerHTML = '';
            items.forEach(item => {
                // Burns have nft_token_id; submissions have id
                const displayId = item.nft_token_id
                    ? item.nft_token_id.slice(0, 8) + '...' + item.nft_token_id.slice(-4)
                    : (item.id || '-');
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td title="${item.nft_token_id || item.id || ''}">${displayId}</td>
                    <td>${item.status}</td>
                    <td>${new Date(item.created_at).toLocaleString()}</td>
                `;
                tbody.appendChild(row);
            });
        } catch (error) {
            logError(`Render history failed: ${error.message}`);
        }
    };

    // Ensure form is hidden on page load
    designForm.classList.remove('active');
    unlockScroll();
    burnRow.style.display = 'none';
    fetchNFTs();
    fetchHistory('transfers', document.querySelector('#burn-history-table tbody'));
    fetchHistory('submissions', document.querySelector('#submission-history-table tbody'));
});