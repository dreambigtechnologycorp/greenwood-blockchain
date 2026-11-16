# Explicit four/seven-node deployment profiles; validator membership remains A/B/C.
locals {
  profile       = jsondecode(file("${path.module}/../topology/profiles.json")).profiles[var.topology_profile]
  operator_host = local.profile.groups.controller[0]
  nodes = { for name, node in local.profile.nodes : name => merge(node, {
    address         = var.node_addresses[name]
    ssh_user        = var.ssh_user
    architecture    = "aarch64"
    provider_host   = contains(node.roles, "provider")
    gateway_address = node.gateway ? var.gateway_address : null
  }) }

  # Derived lists for the Ansible inventory generator -------------------------
  node_hostnames = keys(local.nodes)
  node_addresses = [for k, v in local.nodes : v.address]

  validators = [
    for k, v in local.nodes : {
      hostname        = k
      address         = v.address
      validator_label = v.validator_label
      controller      = v.controller
    }
    if contains(try(v.roles, []), "validator")
  ]

  providers = [
    for k, v in local.nodes : {
      hostname       = k
      address        = v.address
      provider_label = v.provider_label
    }
    if contains(try(v.roles, []), "provider")
  ]

  controllers = [
    for k, v in local.nodes : {
      hostname = k
      address  = v.address
    }
    if v.controller
  ]

  gateways = [
    for k, v in local.nodes : {
      hostname        = k
      address         = v.address
      gateway_address = v.gateway_address
    }
    if v.gateway
  ]
}

# Validation ----------------------------------------------------------------
locals {
  validator_set_ok = sort([for v in local.validators : v.validator_label]) == tolist(["A", "B", "C"])
  provider_set_ok  = sort([for p in local.providers : p.hostname]) == sort(tolist(local.profile.groups.providers))
  controller_ok    = sort([for c in local.controllers : c.hostname]) == tolist(["node-1"])
  gateway_ok       = sort([for g in local.gateways : g.hostname]) == tolist(["node-4"])
  node4_clean      = local.nodes["node-4"].gateway && length(local.nodes["node-4"].roles) == 0 && local.nodes["node-4"].validator_label == null && local.nodes["node-4"].provider_label == null
  topology_ok      = local.validator_set_ok && local.provider_set_ok && local.controller_ok && local.gateway_ok && local.node4_clean
  topology_failure = local.topology_ok ? "" : "TOPOLOGY INVALID"
}

# Node information output for Ansible inventory generation
output "node_topology" {
  description = "Machine-readable node topology for Ansible inventory generation"
  precondition {
    condition     = sort(keys(var.node_addresses)) == sort(keys(local.profile.nodes))
    error_message = "Addresses must exactly match selected profile hosts."
  }
  value = {
    for k, v in local.nodes :
    k => {
      address         = v.address
      ssh_user        = v.ssh_user
      roles           = v.roles
      controller      = v.controller
      validator_label = v.validator_label
      provider_label  = v.provider_label
      provider_host   = v.provider_host
      gateway         = v.gateway
      gateway_address = v.gateway_address
      architecture    = v.architecture
    }
  }
}

# Summary output for human readability
output "summary" {
  description = "Summary of configured nodes"
  value = {
    node_count      = length(local.nodes)
    node_hostnames  = local.node_hostnames
    node_addresses  = local.node_addresses
    validator_count = length(local.validators)
    provider_count  = length(local.providers)
    controller_host = local.controllers[0].hostname
    gateway_host    = local.gateways[0].hostname
    validator_set   = sort([for v in local.validators : v.validator_label])
    provider_set    = sort([for p in local.providers : p.provider_label])
  }
}

output "topology_profile" {
  value = var.topology_profile
}
