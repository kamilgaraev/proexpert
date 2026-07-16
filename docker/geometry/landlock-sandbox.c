#define _GNU_SOURCE

#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <linux/filter.h>
#include <linux/landlock.h>
#include <linux/seccomp.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/prctl.h>
#include <sys/stat.h>
#include <sys/syscall.h>
#include <unistd.h>

static int landlock_create_ruleset(
    const struct landlock_ruleset_attr *attributes,
    size_t size,
    uint32_t flags
)
{
    return (int) syscall(SYS_landlock_create_ruleset, attributes, size, flags);
}

static int landlock_add_path_rule(int ruleset, const char *path, uint64_t allowed_access)
{
    int parent = open(path, O_PATH | O_CLOEXEC);
    if (parent < 0) {
        return -1;
    }

    const struct landlock_path_beneath_attr rule = {
        .allowed_access = allowed_access,
        .parent_fd = parent,
    };
    int status = (int) syscall(
        SYS_landlock_add_rule,
        ruleset,
        LANDLOCK_RULE_PATH_BENEATH,
        &rule,
        0
    );
    close(parent);

    return status;
}

static int landlock_restrict_self(int ruleset)
{
    return (int) syscall(SYS_landlock_restrict_self, ruleset, 0);
}

static int install_seccomp_filter(const char *path)
{
    int descriptor = open(path, O_RDONLY | O_CLOEXEC);
    if (descriptor < 0) {
        return -1;
    }

    struct stat details;
    if (fstat(descriptor, &details) < 0 || details.st_size <= 0
        || details.st_size % (off_t) sizeof(struct sock_filter) != 0
        || details.st_size / (off_t) sizeof(struct sock_filter) > USHRT_MAX) {
        close(descriptor);
        errno = EINVAL;
        return -1;
    }

    size_t size = (size_t) details.st_size;
    struct sock_filter *instructions = malloc(size);
    if (instructions == NULL) {
        close(descriptor);
        return -1;
    }

    size_t offset = 0;
    while (offset < size) {
        ssize_t count = read(descriptor, (char *) instructions + offset, size - offset);
        if (count <= 0) {
            free(instructions);
            close(descriptor);
            errno = count == 0 ? EINVAL : errno;
            return -1;
        }
        offset += (size_t) count;
    }
    close(descriptor);

    const struct sock_fprog program = {
        .len = (unsigned short) (size / sizeof(struct sock_filter)),
        .filter = instructions,
    };
    int status = prctl(PR_SET_SECCOMP, SECCOMP_MODE_FILTER, &program);
    free(instructions);

    return status;
}

int main(int argc, char **argv)
{
    if (argc < 4) {
        return 64;
    }

    char *workspace = realpath(argv[1], NULL);
    if (workspace == NULL) {
        perror("geometry_sandbox_workspace");
        return 66;
    }

    int abi = landlock_create_ruleset(NULL, 0, LANDLOCK_CREATE_RULESET_VERSION);
    if (abi < 3) {
        free(workspace);
        fputs("geometry_sandbox_landlock_unavailable\n", stderr);
        return 69;
    }

    uint64_t read_access = LANDLOCK_ACCESS_FS_EXECUTE
        | LANDLOCK_ACCESS_FS_READ_FILE
        | LANDLOCK_ACCESS_FS_READ_DIR;
    uint64_t write_access = LANDLOCK_ACCESS_FS_WRITE_FILE
        | LANDLOCK_ACCESS_FS_REMOVE_DIR
        | LANDLOCK_ACCESS_FS_REMOVE_FILE
        | LANDLOCK_ACCESS_FS_MAKE_CHAR
        | LANDLOCK_ACCESS_FS_MAKE_DIR
        | LANDLOCK_ACCESS_FS_MAKE_REG
        | LANDLOCK_ACCESS_FS_MAKE_SOCK
        | LANDLOCK_ACCESS_FS_MAKE_FIFO
        | LANDLOCK_ACCESS_FS_MAKE_BLOCK
        | LANDLOCK_ACCESS_FS_MAKE_SYM
        | LANDLOCK_ACCESS_FS_REFER
        | LANDLOCK_ACCESS_FS_TRUNCATE;
    const struct landlock_ruleset_attr attributes = {
        .handled_access_fs = read_access | write_access,
    };

    int ruleset = landlock_create_ruleset(&attributes, sizeof(attributes), 0);
    if (ruleset < 0
        || landlock_add_path_rule(ruleset, "/", read_access) < 0
        || landlock_add_path_rule(ruleset, workspace, read_access | write_access) < 0
        || chdir(workspace) < 0
        || setenv("TMPDIR", workspace, 1) < 0
        || prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) < 0
        || landlock_restrict_self(ruleset) < 0
        || install_seccomp_filter(argv[2]) < 0) {
        perror("geometry_sandbox_setup");
        if (ruleset >= 0) {
            close(ruleset);
        }
        free(workspace);
        return 70;
    }

    close(ruleset);
    free(workspace);
    execvp(argv[3], argv + 3);
    perror("geometry_sandbox_exec");

    return 71;
}
