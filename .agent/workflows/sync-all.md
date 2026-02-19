---
description: Synchronize all active repositories by committing and pushing changes.
---

1.  **Identify Active Repositories**:
    *   List all distinct root directories from the user's workspace (e.g., based on open files or known project paths like `desktop/prohelper`, `desktop/prohelper_admin`).
    *   For each directory, check if it is a git repository (contains `.git`).

2.  **For Each Repository**:
    *   **Check Status**: Run `git status --porcelain` to see if there are any changes.
    *   **If Changes Exist**:
        *   **Smart QA (Pre-check)**: 
            *   Run `git diff --cached` (or `git diff` if not staged) to analyze changes.
            *   **Strict Blockers**: If `console.log`, `print_r`, `var_dump`, or `dd()` are found in the diff, **STOP IMMEDIATELY**. These are never allowed in production.
            *   **Other common issues**: syntax errors or obvious logic flaws.
            *   **Run Linter (Optional)**: If the environment supports it, run a quick lint command.
        *   **Decision**:
            *   If **Strict Blockers** or critical issues are found, **ABORT SYNC** and report to the user.
            *   If only minor issues or no issues, proceed.
        *   **Stage**: Run `git add .`
        *   **Commit**: Generate a concise, conventional commit message based on the changes.
        *   **Push**: Run `git push`.
        *   **Log**: "✅ `{repo_name}`: QA passed and pushed".
    *   **If No Changes**:
        *   **Log**: Output a message indicating already up-to-date (e.g., "✨ `prohelper_admin`: No changes to sync").

3.  **Report**:
    *   Summarize the actions taken for all repositories in a single final message.
