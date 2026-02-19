---
description: Synchronize all active repositories by committing and pushing changes.
---

1.  **Identify Active Repositories**:
    *   List all distinct root directories from the user's workspace (e.g., based on open files or known project paths like `desktop/prohelper`, `desktop/prohelper_admin`).
    *   For each directory, check if it is a git repository (contains `.git`).

2.  **For Each Repository**:
    *   **Check Status**: Run `git status --porcelain` to see if there are any changes.
    *   **If Changes Exist**:
        *   **Stage**: Run `git add .`
        *   **Commit**: Generate a concise, conventional commit message based on the changes (e.g., `fix: ...`, `feat: ...`). Run `git commit -m "your_message"`.
        *   **Push**: Run `git push`. Handle any upstream errors if necessary (e.g., `git push --set-upstream origin current_branch`).
        *   **Log**: Output a message indicating success for this repository (e.g., "✅ `prohelper`: Pushed changes").
    *   **If No Changes**:
        *   **Log**: Output a message indicating already up-to-date (e.g., "✨ `prohelper_admin`: No changes to sync").

3.  **Report**:
    *   Summarize the actions taken for all repositories in a single final message.
