<?php

declare(strict_types=1);

$qcScorecardId = trim((string) ($_GET['qc_scorecard_id'] ?? ''));
$recordingId = trim((string) ($_GET['recording_id'] ?? ''));
$qcLogId = trim((string) ($_GET['qc_log_id'] ?? ''));
$returnUrl = trim((string) ($_GET['return_url'] ?? ''));
$returnParts = $returnUrl !== '' ? parse_url($returnUrl) : false;

// Only permit a local return target supplied by qc_modify_lead.php.
if (!is_array($returnParts) || isset($returnParts['scheme']) || isset($returnParts['host']) || empty($returnParts['path'])) {
    $returnUrl = 'qc_modify_lead.php?' . http_build_query(['qc_log_id' => $qcLogId]);
}
$summaryFile = '/var/spool/asterisk/uploads/ai_summary/' . $recordingId . '_ai_summary.txt';
$savedSummary = '';

if (ctype_digit($recordingId) && is_file($summaryFile) && is_readable($summaryFile)) {
    $summaryContents = file_get_contents($summaryFile);

    if ($summaryContents !== false) {
        $savedSummary = $summaryContents;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Call Summary</title>
    <style>
        :root {
            --summary-bg: #020c12;
            --summary-surface: #06151b;
            --summary-surface-alt: #0a1d24;
            --summary-border: #13515a;
            --summary-accent: #00e6bd;
            --summary-accent-hover: #42f3d1;
            --summary-text: #e8f1f2;
            --summary-muted: #91a4a8;
			--summary-header: #031016;
			--summary-hover: #0c252c;
			--summary-button-text: #00100d;
			--summary-shadow: rgba(0, 0, 0, 0.32);
		}

		html.summary-light-theme {
			--summary-bg: #ffffff;
			--summary-surface: #f1f3f3;
			--summary-surface-alt: #f8f9f9;
			--summary-border: #c4d7d8;
			--summary-accent: #007d69;
			--summary-accent-hover: #006653;
			--summary-text: #15252b;
			--summary-muted: #5d7074;
			--summary-header: #e5ebeb;
			--summary-hover: #e1efec;
			--summary-button-text: #ffffff;
			--summary-shadow: rgba(0, 33, 48, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background: var(--summary-bg);
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 32px 0 56px;
            background:
                radial-gradient(circle at top right, rgba(0, 230, 189, 0.07), transparent 30%),
                var(--summary-bg);
            color: var(--summary-text);
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
        }

        .container {
            width: min(1500px, 94vw);
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 24px;
            padding: 0 2px 18px;
            border-bottom: 1px solid var(--summary-accent);
        }

        .header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            margin: 0;
            color: var(--summary-accent);
            font-size: clamp(24px, 3vw, 34px);
            line-height: 1.2;
            letter-spacing: 0.02em;
        }

        .page-meta {
            margin: 10px 0 0;
            color: var(--summary-muted);
            font-size: 15px;
        }

        .back-button,
        .regenerate-button {
            min-height: 38px;
            padding: 8px 18px;
            background: transparent;
            color: var(--summary-accent);
            border: 1px solid var(--summary-accent);
            border-radius: 5px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .back-button:hover,
        .regenerate-button:hover {
            background: var(--summary-accent);
            color: var(--summary-button-text);
        }

        .regenerate-button {
            background: var(--summary-accent);
            color: var(--summary-button-text);
        }

        .regenerate-button:disabled {
            opacity: .65;
            cursor: wait;
        }

        .generation-status {
            min-height: 20px;
            margin: 12px 0 0;
            color: var(--summary-muted);
            font-size: 14px;
        }

        .generation-status.is-error {
            color: #ff6577;
        }

        .box {
            min-height: 120px;
            padding: 20px;
            overflow-x: auto;
            background: var(--summary-surface);
            color: var(--summary-text);
            border: 1px solid var(--summary-border);
            border-radius: 8px;
            box-shadow: 0 18px 55px var(--summary-shadow);
            white-space: pre-wrap;
            line-height: 1.7;
        }

        #summary-box table {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            background: var(--summary-surface);
            color: var(--summary-text);
            white-space: normal;
        }

        #summary-box th,
        #summary-box td {
            padding: 15px 14px;
            border: 1px solid var(--summary-border);
            text-align: left;
            vertical-align: top;
            font-size: 15px;
            line-height: 1.5;
        }

        #summary-box th {
            background: var(--summary-header);
            color: var(--summary-accent);
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        #summary-box tbody tr:nth-child(even) {
            background: var(--summary-surface-alt);
        }

        #summary-box tbody tr:hover {
            background: var(--summary-hover);
        }

        #summary-box strong {
            color: var(--summary-accent);
        }

        ::selection {
            background: var(--summary-accent);
            color: var(--summary-button-text);
        }

		html.summary-light-theme body {
			background: var(--summary-bg);
		}

        @media (max-width: 700px) {
            body {
                padding-top: 20px;
            }

            .container {
                width: 96vw;
            }

            .box {
                padding: 12px;
            }

            .header-row {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
	<script>
	(function () {
		function selectedTheme() {
			var requested = String(new URLSearchParams(window.location.search).get('qc_theme') || '').toLowerCase();
			if (requested === 'light' || requested === 'dark') return requested;

			var shared = String(localStorage.getItem('qc-theme') || '').toLowerCase();
			if (shared === 'light' || shared === 'dark') return shared;

			try {
				if (window.opener && !window.opener.closed) {
					if (window.opener.document.documentElement.classList.contains('qc-light-theme')) return 'light';
					if (window.opener.document.documentElement.classList.contains('summary-light-theme')) return 'light';
				}
			} catch (error) {}

			return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
		}

		function applySummaryTheme() {
			var theme = selectedTheme();
			document.documentElement.classList.toggle('summary-light-theme', theme === 'light');
			localStorage.setItem('qc-theme', theme);
		}

		applySummaryTheme();
		window.addEventListener('storage', applySummaryTheme);
		if (window.matchMedia) {
			var scheme = window.matchMedia('(prefers-color-scheme: light)');
			if (scheme.addEventListener) scheme.addEventListener('change', applySummaryTheme);
			else if (scheme.addListener) scheme.addListener(applySummaryTheme);
		}
	})();
	</script>
</head>

<body>
    <div class="container">
        <header class="page-header">
            <div class="header-row">
                <h1>AI Call Summary</h1>
                <div class="header-actions">
                    <button id="regenerate-button" class="regenerate-button" type="button"
                        <?php echo ($recordingId === '' || $qcScorecardId === '' || !ctype_digit($qcLogId)) ? 'disabled' : ''; ?>>
                        Regenerate AI Summary
                    </button>
                    <button class="back-button" type="button" onclick="window.location.href=<?php echo htmlspecialchars(json_encode($returnUrl), ENT_QUOTES, 'UTF-8'); ?>">← Back</button>
                </div>
            </div>
            <p class="page-meta">
                <?php if ($recordingId !== ''): ?>
                    Recording ID: <?php echo htmlspecialchars($recordingId); ?>
                <?php endif; ?>
                <?php if ($recordingId !== '' && $qcScorecardId !== ''): ?> · <?php endif; ?>
                <?php if ($qcScorecardId !== ''): ?>
                    Scorecard: <?php echo htmlspecialchars($qcScorecardId); ?>
                <?php endif; ?>
            </p>
            <p id="generation-status" class="generation-status" role="status" aria-live="polite"></p>
        </header>

        <div id="summary-box" class="box">No AI analysis has been generated yet.</div>
    </div>

    <script>
        const savedSummary = <?php echo json_encode($savedSummary); ?>;
        const qcScorecardId = <?php echo json_encode($qcScorecardId); ?>;
        const recordingId = <?php echo json_encode($recordingId); ?>;
        const qcLogId = <?php echo json_encode($qcLogId); ?>;
        const summaryBox = document.getElementById("summary-box");
        const regenerateButton = document.getElementById("regenerate-button");
        const generationStatus = document.getElementById("generation-status");

        function renderSummary(summary) {
            const source = String(summary || "").trim();
            const html = /^<tr[\s>]/i.test(source) ?
                "<table><tbody>" + source + "</tbody></table>" : source;
            const parsed = new DOMParser().parseFromString(html, "text/html");
            const allowedTags = new Set([
                "TABLE", "THEAD", "TBODY", "TFOOT", "TR", "TH", "TD",
                "P", "BR", "STRONG", "EM", "UL", "OL", "LI"
            ]);

            parsed.body.querySelectorAll("*").forEach(element => {
                if (!allowedTags.has(element.tagName)) {
                    element.replaceWith(document.createTextNode(element.textContent));
                    return;
                }

                [...element.attributes].forEach(attribute => {
                    element.removeAttribute(attribute.name);
                });
            });

            summaryBox.replaceChildren(...parsed.body.childNodes);
        }

        if (savedSummary.trim() !== "") {
            renderSummary(savedSummary);
        }

        regenerateButton.addEventListener("click", async () => {
            const originalText = regenerateButton.textContent;
            regenerateButton.disabled = true;
            regenerateButton.textContent = "Regenerating analysis...";
            generationStatus.classList.remove("is-error");
            generationStatus.textContent = "Analyzing the recording again. This may take a few minutes.";

            try {
                const response = await fetch("summarize.php", {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: JSON.stringify({
                        qc_log_id: qcLogId,
                        qc_scorecard_id: qcScorecardId,
                        recording_id: recordingId,
                        analysis_mode: "summary_only",
                        force_regenerate: true
                    })
                });
                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || "Could not regenerate the AI analysis.");
                }

                renderSummary(data.summary || "");
                generationStatus.textContent = "AI summary regenerated successfully.";
            } catch (error) {
                generationStatus.classList.add("is-error");
                generationStatus.textContent = error && error.message
                    ? error.message
                    : "An unexpected error occurred while regenerating the analysis.";
            } finally {
                regenerateButton.disabled = false;
                regenerateButton.textContent = originalText;
            }
        });
    </script>
</body>

</html>
