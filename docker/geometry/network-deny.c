#include <errno.h>
#include <fcntl.h>
#include <seccomp.h>
#include <stddef.h>
#include <sys/socket.h>
#include <unistd.h>

int main(int argc, char **argv)
{
    if (argc != 2) {
        return 64;
    }

    scmp_filter_ctx context = seccomp_init(SCMP_ACT_ALLOW);
    if (context == NULL) {
        return 70;
    }

    const int denied_domains[] = {
        AF_INET,
        AF_INET6,
        AF_NETLINK,
        AF_PACKET,
    };

    for (size_t index = 0; index < sizeof(denied_domains) / sizeof(denied_domains[0]); index++) {
        if (seccomp_rule_add(
            context,
            SCMP_ACT_ERRNO(EPERM),
            SCMP_SYS(socket),
            1,
            SCMP_A0(SCMP_CMP_EQ, denied_domains[index])
        ) < 0) {
            seccomp_release(context);
            return 70;
        }
    }

    int output = open(argv[1], O_WRONLY | O_CREAT | O_TRUNC, 0444);
    if (output < 0) {
        seccomp_release(context);
        return 73;
    }

    int status = seccomp_export_bpf(context, output);
    close(output);
    seccomp_release(context);

    return status < 0 ? 74 : 0;
}
